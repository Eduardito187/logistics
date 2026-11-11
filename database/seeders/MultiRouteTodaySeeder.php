<?php

declare(strict_types=1);

namespace Database\Seeders;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Seeder;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Execution\Domain\Model\RouteStop;
use Logistics\Execution\Domain\Model\VehicleRoute;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint as ExecutionGeoPoint;
use Logistics\Execution\Domain\ValueObject\ProofOfDelivery;
use Logistics\Execution\Domain\ValueObject\RouteDate;
use Logistics\Execution\Domain\ValueObject\RouteStopId;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\VehicleRouteRecord;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\DriverModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\HelperModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Contract\RouteGeometryProvider;
use Logistics\Planning\Domain\Contract\RouteOptimizer;
use Logistics\Planning\Domain\Contract\RouteStop as PlanningRouteStop;
use Logistics\Planning\Domain\Model\DeliveryPlan;
use Logistics\Planning\Domain\Model\PlannedStop;
use Logistics\Planning\Domain\ValueObject\Eta;
use Logistics\Planning\Domain\ValueObject\GeoPoint as PlanningGeoPoint;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlannedStopId;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Domain\Contract\AddressConfidenceScorer;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\Model\Shipment;
use Logistics\Shipments\Domain\Model\ShipmentLine;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\Customer;
use Logistics\Shipments\Domain\ValueObject\DeliverySlot;
use Logistics\Shipments\Domain\ValueObject\GeoPoint as ShipmentGeoPoint;
use Logistics\Shipments\Domain\ValueObject\Money;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\ProductImageUrl;
use Logistics\Shipments\Domain\ValueObject\ProductName;
use Logistics\Shipments\Domain\ValueObject\ShipmentLineId;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;
use Logistics\Shipments\Domain\ValueObject\Sku;
use Logistics\Shipments\Domain\ValueObject\SourceReference;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\PhysicalUnitRecord;
use Logistics\Telemetry\Application\Command\RecordVehiclePings\RecordVehiclePings;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\PingRecord;

/**
 * VARIAS rutas de reparto EN CURSO para HOY, en vehículos DISTINTOS al escenario principal
 * (TodayScenarioSeeder maneja 904U10): así el «Mapa de flota en vivo» muestra varios camiones a la vez y
 * «Planes de reparto» lista varias rutas del día. Reusa el MISMO pipeline de dominio que el escenario
 * (recibir envío → plan publicado con geometría → ruta del chofer → entregas con prueba → telemetría),
 * sin flujos de tienda para mantenerlo enfocado. SOLO entornos locales/dev.
 *
 * Uso:
 *   docker compose exec -T app php artisan db:seed --class="Database\Seeders\MultiRouteTodaySeeder"
 *
 * Idempotente: envíos por (fuente, id externo fechado MR-{placa}-{ymd}-NN); plan por (fecha, vehículo);
 * ruta por (vehículo, fecha); telemetría por (vehículo, día, GPS). Correrlo dos veces no duplica.
 */
final class MultiRouteTodaySeeder extends Seeder
{
    private const string TIMEZONE = 'America/La_Paz';

    /** Centro de distribución (coordenada de arranque de cada ruta). */
    private const float CD_LAT = -17.7490;

    private const float CD_LNG = -63.1540;

    /** Paradas ya entregadas al sembrar (la siguiente queda como parada actual). */
    private const int COMPLETED_STOPS = 2;

    private const int SERVICE_MINUTES = 25;

    private DateTimeZone $tz;

    private DateTimeImmutable $now;

    private string $date;

    public function __construct(
        private readonly CommandBus $bus,
        private readonly ShipmentRepository $shipments,
        private readonly AddressConfidenceScorer $scorer,
        private readonly PlanRepository $plans,
        private readonly RouteOptimizer $optimizer,
        private readonly RouteGeometryProvider $geometryProvider,
        private readonly VehicleRouteRepository $routes,
        private readonly DeliveryRepository $deliveries,
    ) {
        $this->tz = new DateTimeZone(self::TIMEZONE);
        $this->now = new DateTimeImmutable('now', $this->tz);
        $this->date = $this->now->format('Y-m-d');
    }

    public function run(): void
    {
        if (!app()->environment(['local', 'development', 'dev'])) {
            if ($this->command !== null) {
                $this->command->warn('MultiRouteTodaySeeder solo corre en local/dev; no se hizo nada.');
            }

            return;
        }

        // Fija "ahora" a media mañana para que la ruta en curso tenga paradas entregadas y GPS coherentes.
        $this->now = new DateTimeImmutable($this->date.' 11:00:00', $this->tz);

        $drivers = DriverModel::query()->where('active', true)->orderBy('name')->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)->all();
        /** @var list<string> $helperIds */
        $helperIds = HelperModel::query()->where('active', true)->orderBy('name')->limit(2)->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)->all();

        $i = 0;
        foreach ($this->routeDefs() as $def) {
            $vehicle = VehicleModel::query()->where('plate', $def['plate'])->first();
            if ($vehicle === null) {
                $this->say('warn', "Vehículo {$def['plate']} no existe; se omite esa ruta.");

                continue;
            }
            $driverId = $drivers[$i % max(1, count($drivers))] ?? null;
            if ($driverId === null) {
                $this->say('warn', 'No hay conductores activos; se omite.');

                continue;
            }
            $this->seedRoute((string) $vehicle->id, $driverId, $helperIds, $def);
            $i++;
        }

        $this->say('info', "MultiRouteTodaySeeder: {$i} rutas en curso sembradas para {$this->date}.");
    }

    /**
     * Pipeline de UNA ruta: envíos → plan publicado → ruta con primeras paradas entregadas → entregas con
     * prueba → telemetría en vivo.
     *
     * @param list<string>                                                                                                                                                                                                                          $helperIds
     * @param array{plate: string, origin_name: string, orders: list<array{ref: string, customer: string, phone: string, address: string, reference: ?string, lat: float, lng: float, lines: list<array{0: string, 1: string, 2: int, 3: float}>}>} $def
     */
    private function seedRoute(string $vehicleId, string $driverId, array $helperIds, array $def): void
    {
        /** @var list<Shipment> $shipments */
        $shipments = [];
        foreach ($def['orders'] as $order) {
            $shipments[] = $this->ensureShipment($def['plate'], $order);
        }
        if ($shipments === []) {
            return;
        }

        $plan = $this->ensurePlan($vehicleId, $shipments);
        $this->markPlanned($shipments);
        $route = $this->ensureRoute($vehicleId, $driverId, $helperIds, $plan, $shipments);
        $this->ensureDeliveries($vehicleId, $route, $shipments, $driverId);
        $this->ensureTelemetry($vehicleId, $driverId, $route);
    }

    /**
     * @param array{ref: string, customer: string, phone: string, address: string, reference: ?string, lat: float, lng: float, lines: list<array{0: string, 1: string, 2: int, 3: float}>} $order
     */
    private function ensureShipment(string $plate, array $order): Shipment
    {
        $externalId = 'MR-'.$plate.'-'.$this->now->format('ymd').'-'.$order['ref'];
        $source = OrderSource::from('SAP');
        $reference = SourceReference::of($source, $externalId);

        $existing = $this->shipments->findBySourceReference($reference);
        if ($existing !== null) {
            return $existing;
        }

        $address = Address::of($order['address'], $order['reference'], ShipmentGeoPoint::of($order['lat'], $order['lng']));

        $lines = [];
        foreach ($order['lines'] as [$sku, $name, $qty, $price]) {
            $lines[] = ShipmentLine::create(
                ShipmentLineId::generate(),
                Sku::of($sku),
                ProductName::of($name),
                $qty,
                ProductImageUrl::fromNullable('https://picsum.photos/seed/'.strtolower($sku).'/200/200'),
                Money::fromNullable($price),
            );
        }

        $shipment = Shipment::receive(
            $this->shipments->nextIdentity(),
            $source,
            $reference,
            Customer::of($order['customer'], null, $order['phone'], null),
            $address,
            $this->scorer->score($address),
            $lines,
            null,
        );
        $shipment->assignDeliverySlot(DeliverySlot::of($this->date, '08:00', '18:00'));
        $this->shipments->save($shipment);

        return $shipment;
    }

    /**
     * Plan publicado del vehículo para hoy (orden del optimizador, ETA desplazada para dejar la ruta en
     * curso, geometría por calles). Idempotente por (fecha, vehículo).
     *
     * @param list<Shipment> $shipments
     */
    private function ensurePlan(string $vehicleId, array $shipments): DeliveryPlan
    {
        $planDate = PlanDate::fromString($this->date);
        foreach ($this->plans->findByDate($planDate) as $existing) {
            if ($existing->vehicleId() === $vehicleId) {
                return $existing;
            }
        }

        $byId = [];
        $routeStops = [];
        foreach ($shipments as $shipment) {
            $id = $shipment->id()->toString();
            $byId[$id] = $shipment;
            $point = $shipment->address()->geoPoint;
            assert($point !== null);
            $routeStops[] = PlanningRouteStop::of($id, $point->lat, $point->lng);
        }

        $optimized = $this->optimizer->optimize($this->now, $routeStops);

        $first = $byId[$optimized[0]->shipmentId]->address()->geoPoint;
        assert($first !== null);
        $cdLegMinutes = (int) ceil(PlanningGeoPoint::of(self::CD_LAT, self::CD_LNG)->haversineMeters(
            PlanningGeoPoint::of($first->lat, $first->lng),
        ) / 1000 / 25 * 60) + 10;

        $offsets = [];
        foreach ($optimized as $index => $stop) {
            $offsets[$stop->shipmentId] = $cdLegMinutes + $stop->eta->minutesFromDeparture + self::SERVICE_MINUTES * $index;
        }

        // Salida para que la parada #COMPLETED_STOPS caiga ~10 min después de "ahora" (ruta en curso).
        $currentOffset = $offsets[$optimized[self::COMPLETED_STOPS]->shipmentId ?? $optimized[0]->shipmentId];
        $departAt = $this->departureFor($currentOffset);

        $plan = DeliveryPlan::startDraft($this->plans->nextIdentity(), $planDate, $vehicleId);
        foreach ($optimized as $stop) {
            $shipment = $byId[$stop->shipmentId];
            $plan->assignShipment(
                PlannedStopId::generate(),
                $stop->shipmentId,
                $shipment->sourceReference()->externalId,
                $shipment->customerName(),
                $shipment->address()->text,
            );
        }

        $ordered = [];
        $etas = [];
        foreach ($optimized as $stop) {
            $ordered[] = $stop->shipmentId;
            $minutes = $offsets[$stop->shipmentId];
            $etas[$stop->shipmentId] = Eta::at($departAt->modify("+{$minutes} minutes"), $minutes);
        }

        $plan->proposeRoute($ordered, $etas);
        $plan->recomputeLoadPlan();

        $points = [PlanningGeoPoint::of(self::CD_LAT, self::CD_LNG)];
        foreach ($ordered as $shipmentId) {
            $point = $byId[$shipmentId]->address()->geoPoint;
            assert($point !== null);
            $points[] = PlanningGeoPoint::of($point->lat, $point->lng);
        }
        $geometry = $this->geometryProvider->geometryFor($points);
        if ($geometry !== null) {
            $plan->attachRouteGeometry($geometry);
        }

        $plan->publish();
        $this->plans->save($plan);

        return $plan;
    }

    private function departureFor(int $currentStopOffsetMinutes): DateTimeImmutable
    {
        $target = $this->now->modify('+10 minutes')->modify("-{$currentStopOffsetMinutes} minutes");
        $earliest = new DateTimeImmutable($this->date.' 07:00:00', $this->tz);
        $latest = new DateTimeImmutable($this->date.' 16:30:00', $this->tz);
        $capped = $target > $latest ? $latest : $target;
        $depart = $target < $earliest ? $earliest : $capped;
        $minute = (int) $depart->format('i');

        return $depart->setTime((int) $depart->format('H'), $minute - ($minute % 5), 0);
    }

    /**
     * @param list<Shipment> $shipments
     */
    private function markPlanned(array $shipments): void
    {
        foreach ($shipments as $shipment) {
            $fresh = $this->shipments->findById($shipment->id());
            if ($fresh !== null && $fresh->status() === ShipmentStatus::Received) {
                $fresh->markPlanned();
                $this->shipments->save($fresh);
            }
        }
    }

    /**
     * @param list<string>   $helperIds
     * @param list<Shipment> $shipments
     */
    private function ensureRoute(string $vehicleId, string $driverId, array $helperIds, DeliveryPlan $plan, array $shipments): VehicleRoute
    {
        $date = RouteDate::fromString($this->date);
        $route = $this->routes->findByVehicleAndDate($vehicleId, $date);

        if ($route === null) {
            $byId = [];
            foreach ($shipments as $shipment) {
                $byId[$shipment->id()->toString()] = $shipment;
            }

            $stops = [];
            foreach ($this->planStops($plan) as $planned) {
                $point = isset($byId[$planned->shipmentId()]) ? $byId[$planned->shipmentId()]->address()->geoPoint : null;
                $stops[] = RouteStop::plan(
                    RouteStopId::generate(),
                    $planned->shipmentId(),
                    $planned->sequence()->value,
                    $planned->orderReference(),
                    $planned->customerName(),
                    $planned->address(),
                    $planned->eta()?->arrivalAt,
                    $point?->lat,
                    $point?->lng,
                );
            }

            $route = VehicleRoute::assemble($this->routes->nextIdentity(), $vehicleId, $date, $stops);
            foreach (array_slice($stops, 0, self::COMPLETED_STOPS) as $index => $stop) {
                $route->completeStop($stop->shipmentId(), $this->settledAt($stop->etaAt(), $index));
            }
            $this->routes->save($route);
        }

        $record = VehicleRouteRecord::query()->where('id', $route->id()->toString())->first();
        if ($record !== null) {
            $record->driver_id = $driverId;
            $record->helper_ids = $helperIds;
            $record->save();
        }

        return $route;
    }

    /**
     * @return list<PlannedStop>
     */
    private function planStops(DeliveryPlan $plan): array
    {
        $stops = array_values($plan->stops());
        usort($stops, static fn (PlannedStop $a, PlannedStop $b): int => $a->sequence()->value <=> $b->sequence()->value);

        return $stops;
    }

    private function settledAt(?DateTimeImmutable $eta, int $index): DateTimeImmutable
    {
        $cap = $this->now->modify('-'.((self::COMPLETED_STOPS - $index) * 12).' minutes');
        $candidate = ($eta ?? $cap)->setTimezone($this->tz)->modify('+4 minutes');

        return $candidate < $cap ? $candidate : $cap;
    }

    /**
     * @param list<Shipment> $shipments
     */
    private function ensureDeliveries(string $vehicleId, VehicleRoute $route, array $shipments, string $driverId): void
    {
        $byId = [];
        foreach ($shipments as $shipment) {
            $byId[$shipment->id()->toString()] = $shipment;
        }
        $loadedAt = new DateTimeImmutable($this->date.' 07:00:00', $this->tz);
        $device = DeviceContext::of('scenario-multi-device', $driverId);
        $cdGeo = ExecutionGeoPoint::of(self::CD_LAT, self::CD_LNG);

        foreach ($route->stops() as $stop) {
            $shipmentId = $stop->shipmentId();
            if ($this->deliveries->findByShipmentId($shipmentId) !== null) {
                continue;
            }
            $units = $this->unitIds($shipmentId);
            $key = "multi-{$this->date}-{$shipmentId}";

            $delivery = Delivery::start($this->deliveries->nextIdentity(), $shipmentId, $vehicleId);
            foreach ($units as $u => $unitId) {
                $delivery->recordPreparationScan($unitId, $cdGeo, $device, $loadedAt->modify("+{$u} minutes"), "{$key}-prep-{$u}");
            }
            foreach ($units as $u => $unitId) {
                $delivery->recordLoadScan($unitId, $cdGeo, $device, $loadedAt->modify('+'.(30 + $u).' minutes'), "{$key}-load-{$u}");
            }
            $settled = $stop->settledAt();
            if ($settled !== null) {
                $geo = ExecutionGeoPoint::of($stop->lat() ?? self::CD_LAT, $stop->lng() ?? self::CD_LNG);
                $customer = $byId[$shipmentId] ?? null;
                $delivery->recordProofOfDelivery(
                    $units[0] ?? $shipmentId,
                    ProofOfDelivery::of('FIRMA-'.strtoupper(substr(md5($shipmentId), 0, 8)).($customer !== null ? ' '.$customer->customerName() : ''), null, $settled),
                    $geo,
                    $device,
                    $settled,
                    "{$key}-pod",
                );
            }
            $this->deliveries->save($delivery);
        }
    }

    /**
     * @return list<string>
     */
    private function unitIds(string $shipmentId): array
    {
        return array_values(
            PhysicalUnitRecord::query()
                ->where('shipment_id', $shipmentId)
                ->orderBy('line_id')->orderBy('unit_index')->orderBy('piece_index')
                ->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all(),
        );
    }

    private function ensureTelemetry(string $vehicleId, string $driverId, VehicleRoute $route): void
    {
        $gps = 'scenario-multi-gps-'.substr($vehicleId, 0, 8);
        $already = PingRecord::query()
            ->where('vehicle_id', $vehicleId)->where('device_id', $gps)
            ->where('recorded_at', '>=', new DateTimeImmutable($this->date.' 00:00:00', $this->tz))->exists();
        if ($already) {
            return;
        }

        // Recorrido: CD → paradas cerradas → 75% hacia la parada actual.
        $waypoints = [[self::CD_LAT, self::CD_LNG]];
        $stops = $route->stops();
        foreach (array_slice($stops, 0, self::COMPLETED_STOPS) as $stop) {
            if ($stop->lat() !== null && $stop->lng() !== null) {
                $waypoints[] = [$stop->lat(), $stop->lng()];
            }
        }
        $current = $stops[self::COMPLETED_STOPS] ?? null;
        $last = $waypoints[count($waypoints) - 1];
        if ($current !== null && $current->lat() !== null && $current->lng() !== null) {
            $waypoints[] = [$last[0] + ($current->lat() - $last[0]) * 0.75, $last[1] + ($current->lng() - $last[1]) * 0.75];
        }

        // Densificar.
        $trail = [];
        for ($i = 1, $c = count($waypoints); $i < $c; $i++) {
            [$fromLat, $fromLng] = $waypoints[$i - 1];
            [$toLat, $toLng] = $waypoints[$i];
            for ($step = ($i === 1 ? 0 : 1); $step <= 12; $step++) {
                $f = $step / 12;
                $trail[] = [$fromLat + ($toLat - $fromLat) * $f, $fromLng + ($toLng - $fromLng) * $f];
            }
        }

        $start = $this->now->modify('-2 hours');
        $end = $this->now->modify('-1 minute');
        $span = max(60, $end->getTimestamp() - $start->getTimestamp());
        $points = count($trail);
        $pings = [];
        $previous = null;
        foreach ($trail as $n => [$lat, $lng]) {
            $at = $start->modify('+'.(int) round($span * $n / max(1, $points - 1)).' seconds');
            $speed = 0.0;
            if ($previous !== null) {
                $meters = PlanningGeoPoint::of($previous[0], $previous[1])->haversineMeters(PlanningGeoPoint::of($lat, $lng));
                $seconds = max(1, $at->getTimestamp() - $previous[2]->getTimestamp());
                $speed = min(60.0, round($meters / $seconds * 3.6, 1));
            }
            $pings[] = [
                'recorded_at' => $at->format(DateTimeInterface::ATOM),
                'lat' => round($lat, 7),
                'lng' => round($lng, 7),
                'speed_kph' => $n === $points - 1 ? 18.0 : max(5.0, $speed),
                'heading' => 180,
                'accuracy_m' => 6.0,
                'ignition' => true,
                'device_id' => $gps,
            ];
            $previous = [$lat, $lng, $at];
        }

        $this->bus->dispatch(new RecordVehiclePings($vehicleId, $driverId, $route->id()->toString(), 'app', $pings));
    }

    private function say(string $level, string $message): void
    {
        if ($this->command === null) {
            return;
        }
        $level === 'warn' ? $this->command->warn($message) : $this->command->info($message);
    }

    /**
     * Rutas a sembrar: vehículos DISTINTOS al escenario principal, cada uno en una zona de Santa Cruz.
     *
     * @return list<array{plate: string, origin_name: string, orders: list<array{ref: string, customer: string, phone: string, address: string, reference: ?string, lat: float, lng: float, lines: list<array{0: string, 1: string, 2: int, 3: float}>}>}>
     */
    private function routeDefs(): array
    {
        return [
            ['plate' => '161W94', 'origin_name' => 'Norte', 'orders' => [
                ['ref' => '01', 'customer' => 'Fernando Áñez Rivero', 'phone' => '+591 70011223', 'address' => 'Av. Banzer 6to anillo, Barrio Las Palmas, Santa Cruz', 'reference' => 'Casa blanca con portón negro', 'lat' => -17.7281, 'lng' => -63.1686, 'lines' => [['REF-SAMS-500', 'Refrigerador Samsung 500 L', 1, 6290.00]]],
                ['ref' => '02', 'customer' => 'Patricia Melgar Soliz', 'phone' => '+591 71122334', 'address' => 'Av. Beni 4to anillo, Santa Cruz', 'reference' => 'Edificio Beni, dpto 5B', 'lat' => -17.7488, 'lng' => -63.1801, 'lines' => [['LAV-SAMS-14', 'Lavadora Samsung 14 kg', 1, 5190.00], ['SEC-SAMS-10', 'Secadora Samsung 10 kg', 1, 4290.00]]],
                ['ref' => '03', 'customer' => 'Hugo Suárez Vargas', 'phone' => '+591 72233445', 'address' => 'Barrio Hamacas, 3er anillo, Santa Cruz', 'reference' => 'Frente a la plaza', 'lat' => -17.7564, 'lng' => -63.1623, 'lines' => [['TV-LG-65', 'Televisor LG 65" OLED', 1, 9990.00]]],
                ['ref' => '04', 'customer' => 'Rosa Vaca Áñez', 'phone' => '+591 73344556', 'address' => 'Av. Mutualista 5to anillo, Santa Cruz', 'reference' => 'Casa esquina', 'lat' => -17.7405, 'lng' => -63.1950, 'lines' => [['COC-WHI-5H', 'Cocina Whirlpool 5 hornallas', 1, 3490.00]]],
            ]],
            ['plate' => '531Y80', 'origin_name' => 'Sur', 'orders' => [
                ['ref' => '01', 'customer' => 'Marco Pinto Ledezma', 'phone' => '+591 74455667', 'address' => 'Barrio Urbarí, 3er anillo sur, Santa Cruz', 'reference' => 'Portón café', 'lat' => -17.7955, 'lng' => -63.1995, 'lines' => [['LAP-DELL-15', 'Laptop Dell 15" Core i7', 1, 7490.00]]],
                ['ref' => '02', 'customer' => 'Silvia Rocha Mendez', 'phone' => '+591 75566778', 'address' => 'Av. Santos Dumont 6to anillo, Santa Cruz', 'reference' => 'Condominio Sur, casa 8', 'lat' => -17.8071, 'lng' => -63.1876, 'lines' => [['MIC-LG-30', 'Microondas LG 30 L', 1, 1190.00], ['LIC-OST-700', 'Licuadora Oster 700 W', 1, 720.00]]],
                ['ref' => '03', 'customer' => 'Jorge Áñez Paz', 'phone' => '+591 76677889', 'address' => 'Plan 3000, UV 170, Santa Cruz', 'reference' => 'Tienda de la esquina', 'lat' => -17.8281, 'lng' => -63.1414, 'lines' => [['VEN-SAMS-20', 'Ventilador Samsung 20"', 2, 450.00]]],
                ['ref' => '04', 'customer' => 'Lucía Terceros Vaca', 'phone' => '+591 77788990', 'address' => 'Barrio El Trompillo, 2do anillo, Santa Cruz', 'reference' => 'Al lado del mercado', 'lat' => -17.8010, 'lng' => -63.1740, 'lines' => [['REF-MAB-350', 'Refrigerador Mabe 350 L', 1, 4790.00]]],
            ]],
            ['plate' => '639W82', 'origin_name' => 'Este', 'orders' => [
                ['ref' => '01', 'customer' => 'Daniel Justiniano Roca', 'phone' => '+591 78899001', 'address' => 'Av. Virgen de Cotoca 4to anillo, Santa Cruz', 'reference' => 'Casa de dos plantas', 'lat' => -17.7735, 'lng' => -63.1402, 'lines' => [['TV-SAMS-50', 'Televisor Samsung 50" 4K', 1, 4490.00]]],
                ['ref' => '02', 'customer' => 'Carmen Áñez Suárez', 'phone' => '+591 79900112', 'address' => 'Barrio Guapay, 5to anillo este, Santa Cruz', 'reference' => 'Frente a la cancha', 'lat' => -17.7821, 'lng' => -63.1280, 'lines' => [['ASP-LG-22', 'Aspiradora LG 2200 W', 1, 1390.00]]],
                ['ref' => '03', 'customer' => 'Ramiro Peña Gutiérrez', 'phone' => '+591 60011223', 'address' => 'Villa 1ro de Mayo, UV 100, Santa Cruz', 'reference' => 'Casa amarilla', 'lat' => -17.7588, 'lng' => -63.1249, 'lines' => [['PLA-PHI-STEAM', 'Plancha a vapor Philips', 1, 390.00], ['OLL-REC-5L', 'Olla arrocera 5 L', 1, 380.00]]],
                ['ref' => '04', 'customer' => 'Elena Vargas Rojas', 'phone' => '+591 61122334', 'address' => 'Av. Paraguá 6to anillo, Santa Cruz', 'reference' => 'Condominio Este', 'lat' => -17.7498, 'lng' => -63.1320, 'lines' => [['FRE-OST-5L', 'Freidora de aire Oster 5 L', 1, 1090.00]]],
            ]],
        ];
    }
}
