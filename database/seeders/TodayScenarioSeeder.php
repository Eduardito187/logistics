<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Logistics\Execution\Application\Command\OpenNodeReception\OpenNodeReception;
use Logistics\Execution\Application\Command\ReceiveUnitAtNode\ReceiveUnitAtNode;
use Logistics\Execution\Application\Command\ReconcileNodeReception\ReconcileNodeReception;
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
use Logistics\Fleet\Application\Command\AssembleCrew\AssembleCrew;
use Logistics\Fleet\Application\Command\ChangeVehicleStatus\ChangeVehicleStatus;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\CrewModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\DriverModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\HelperModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Labels\Application\Command\EmitShipmentLabels\EmitShipmentLabels;
use Logistics\Labels\Application\Dto\OrderLineInput;
use Logistics\Labels\Infrastructure\Persistence\Eloquent\EmittedLabelRecord;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
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
use Logistics\Tracking\Application\Command\OpenTracking\OpenTracking;
use Logistics\Tracking\Application\Command\PlaceShipmentOnRoute\PlaceShipmentOnRoute;
use Logistics\Tracking\Application\Command\ProjectExecutionEvent\ProjectExecutionEvent;
use Logistics\Tracking\Application\Command\ProjectUnitExecutionEvent\ProjectUnitExecutionEvent;
use RuntimeException;

/**
 * Escenario operativo de HOY en Santa Cruz de la Sierra, para probar la app interna (Flutter) con datos
 * reales de punta a punta. SOLO entornos locales/dev: en cualquier otro entorno no hace nada.
 *
 * Uso (dentro del contenedor):
 *   docker compose exec -T app php artisan db:seed --class="Database\Seeders\TodayScenarioSeeder"
 * o con make:
 *   make artisan c="db:seed --class=Database\\Seeders\\TodayScenarioSeeder"
 *
 * Qué deja armado para la fecha de hoy (zona America/La_Paz):
 *   1. Usuarios de prueba por rol (chofer, preparador, tienda, transportista, supervisor) con contraseña
 *      `password123`; el chofer ligado a un conductor de flota y la tienda a su nodo (T-SCZ-01).
 *   2. Tripulación del día (chofer + 2 ayudantes + camión del CD Santa Cruz + turno) vía AssembleCrew,
 *      y el camión pasa a `in_service`.
 *   3. Envíos de clientes en barrios reales de Santa Cruz (con teléfono +591, referencia, líneas con
 *      imagen) y un envío "Recojo en tienda" con destino al nodo de la tienda; etiquetas QR emitidas.
 *   4. Plan del día publicado para el camión (orden del optimizador, ETA a lo largo del día, plan de
 *      carga y geometría por calles calculada en el servidor; degrada a línea recta si OSRM no responde).
 *   5. Ruta del chofer armada desde ese plan, EN CURSO: dos paradas entregadas (con settled_at), la
 *      tercera es la parada actual y el resto pendientes; entregas en DELIVERED / LOADED acorde.
 *   6. Seguimiento (tracking) por envío y por bulto, recorrido GPS del camión hasta "ahora" (telemetría
 *      en vivo para el supervisor) y, para la tienda, una recepción abierta y un recojo listo en mostrador.
 *
 * Idempotente: cada bloque se guarda por su clave de negocio (email, fuente+id externo, vehículo+fecha,
 * envío, orden de etiquetas, recepción por envío+nodo, pings del día del escenario). Correrlo dos veces
 * el mismo día no duplica nada; correrlo otro día arma el escenario de ese día (ids externos fechados).
 * Recorre los agregados y casos de uso del dominio, no inserta filas sueltas.
 */
final class TodayScenarioSeeder extends Seeder
{
    private const string TIMEZONE = 'America/La_Paz';

    private const string PASSWORD = 'password123';

    /** Camión del escenario: base en el CD Santa Cruz (FleetSeeder). */
    private const string ROUTE_VEHICLE_PLATE = '904U10';

    /** Camión que hizo el traspaso CD → tienda de la mañana (base Equipetrol, Santa Cruz). */
    private const string TRANSFER_VEHICLE_PLATE = '131O17';

    private const string CD_CODE = 'CD-SCZ-01';

    private const string STORE_CODE = 'T-SCZ-01';

    /** Código de nodo corto para la etiqueta (patrón del QR: [A-Z0-9-]{1,20}). */
    private const string LABEL_ORIGIN = 'CD-SCZ';

    private const string DEVICE_ID = 'scenario-today-device';

    private const string GPS_DEVICE_ID = 'scenario-today-gps';

    /** Paradas ya entregadas al momento de sembrar (la siguiente es la parada actual). */
    private const int COMPLETED_STOPS = 2;

    /** Minutos de atención por parada (bajar, firmar, cobrar) que se suman al manejo. */
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
            $this->say('warn', 'TodayScenarioSeeder solo corre en entornos locales/dev; no se hizo nada.');

            return;
        }

        $cd = $this->node(self::CD_CODE);
        $store = $this->node(self::STORE_CODE);
        $vehicle = $this->vehicle(self::ROUTE_VEHICLE_PLATE);
        $transferVehicle = $this->vehicle(self::TRANSFER_VEHICLE_PLATE);

        // 1) Usuarios por rol.
        $users = $this->ensureUsers((string) $store->id, (string) $vehicle->id);
        $driverId = (string) $users['chofer']->driver_id;

        // 2) Tripulación del día + camión en servicio.
        $crew = $this->ensureCrew($driverId, (string) $vehicle->id);
        $this->ensureVehicleInService((string) $vehicle->id);

        // 3) Envíos (clientes + recojo en tienda) y sus etiquetas.
        $routeShipments = [];
        foreach ($this->customerOrders() as $order) {
            $routeShipments[] = $this->ensureShipment($order, null);
        }
        $pickupOrders = $this->storeOrders((string) $store->id);
        $openReceptionShipment = $this->ensureShipment($pickupOrders[0], (string) $store->id);
        $readyPickupShipment = $this->ensureShipment($pickupOrders[1], (string) $store->id);

        foreach ([...$routeShipments, $openReceptionShipment, $readyPickupShipment] as $shipment) {
            $this->ensureLabels($shipment, $shipment->destinationNodeId() !== null ? self::STORE_CODE : 'C');
        }

        // 4) Plan del día publicado (con geometría).
        $plan = $this->ensurePlan((string) $vehicle->id, $routeShipments, (float) $cd->lat, (float) $cd->lng);
        $this->markPlanned($routeShipments);

        // 5) Ruta del chofer en curso + entregas + tracking.
        $route = $this->ensureRoute((string) $vehicle->id, $plan, $routeShipments, $crew);
        $this->ensureRouteDeliveries((string) $vehicle->id, $route, $routeShipments, $users['chofer']);

        // 6) Telemetría (supervisor) y tienda (recepción abierta + recojo listo).
        $this->ensureTelemetry((string) $vehicle->id, $driverId, $route, (float) $cd->lat, (float) $cd->lng);
        $this->ensureStoreFlows(
            (string) $transferVehicle->id,
            $cd,
            $store,
            $openReceptionShipment,
            $readyPickupShipment,
            $users['tienda'],
        );

        $this->summary($users, $vehicle, $crew, $plan, $route, $openReceptionShipment, $readyPickupShipment);
    }

    // ---------------------------------------------------------------------------------------------
    // 1) Usuarios
    // ---------------------------------------------------------------------------------------------

    /**
     * Garantiza las cuentas de prueba por rol. Si ya existen, asegura rol, contraseña conocida y vínculos
     * (chofer → conductor de flota habilitado para el camión; tienda → nodo T-SCZ-01).
     *
     * @return array<string, User>
     */
    private function ensureUsers(string $storeNodeId, string $vehicleId): array
    {
        $accounts = [
            'chofer' => ['Chofer Santa Cruz', Role::Chofer],
            'preparador' => ['Preparador CD Santa Cruz', Role::Preparador],
            'tienda' => ['Tienda Santa Cruz Norte', Role::Tienda],
            'transportista' => ['Transportista Externo', Role::Transportista],
            'supervisor' => ['Supervisor Santa Cruz', Role::Supervisor],
        ];

        $users = [];
        foreach ($accounts as $key => [$name, $role]) {
            $user = User::query()->firstOrCreate(
                ['email' => "{$key}@dismac.test"],
                ['name' => $name, 'password' => self::PASSWORD],
            );
            // Contraseña conocida para probar (solo dev): se reescribe solo si no coincide.
            if (!Hash::check(self::PASSWORD, (string) $user->password)) {
                $user->password = self::PASSWORD;
                $user->save();
            }
            $user->assignRole($role);
            $users[$key] = $user;
        }

        // Chofer ↔ conductor de flota: se respeta el vínculo existente si el conductor sigue activo.
        $chofer = $users['chofer'] ?? throw new RuntimeException('Falta la cuenta chofer.');
        $linked = $chofer->driver_id !== null
            ? DriverModel::query()->where('id', $chofer->driver_id)->where('active', true)->value('id')
            : null;
        if ($linked === null) {
            $driver = DriverModel::query()->where('active', true)->whereJsonContains('enabled_vehicle_ids', $vehicleId)->orderBy('id')->value('id')
                ?? DriverModel::query()->where('active', true)->orderBy('id')->value('id');
            if ($driver === null) {
                throw new RuntimeException('No hay conductores activos en fleet_drivers (correr FleetSeeder).');
            }
            $chofer->driver_id = (string) $driver;
            $chofer->save();
        }

        // Tienda ↔ nodo de tienda.
        $tienda = $users['tienda'] ?? throw new RuntimeException('Falta la cuenta tienda.');
        if ($tienda->node_id !== $storeNodeId) {
            $tienda->node_id = $storeNodeId;
            $tienda->save();
        }

        return $users;
    }

    // ---------------------------------------------------------------------------------------------
    // 2) Flota
    // ---------------------------------------------------------------------------------------------

    /**
     * Tripulación del día para el chofer en el camión del escenario (AssembleCrew, con turno). Guardia por
     * (chofer, fecha) y por (vehículo, fecha): si ya hay una, se reutiliza.
     */
    private function ensureCrew(string $driverId, string $vehicleId): CrewModel
    {
        $existing = CrewModel::query()->where('driver_id', $driverId)->whereDate('assembled_for', $this->date)->first()
            ?? CrewModel::query()->where('vehicle_id', $vehicleId)->whereDate('assembled_for', $this->date)->first();

        if ($existing === null) {
            /** @var list<string> $helperIds */
            $helperIds = HelperModel::query()->where('active', true)->orderBy('name')->limit(2)->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)->all();

            $this->bus->dispatch(new AssembleCrew(
                $driverId,
                $helperIds,
                $vehicleId,
                $this->date,
                $this->date.'T07:30:00-04:00',
                $this->date.'T17:30:00-04:00',
            ));

            $existing = CrewModel::query()->where('driver_id', $driverId)->whereDate('assembled_for', $this->date)->first();
        }

        if ($existing === null) {
            throw new RuntimeException('No se pudo armar la tripulación del día.');
        }

        return $existing;
    }

    /**
     * El camión sale a repartir: pasa de `available` a `in_service` (no toca uno en mantenimiento/baja).
     */
    private function ensureVehicleInService(string $vehicleId): void
    {
        $status = VehicleModel::query()->where('id', $vehicleId)->value('status');
        if ($status === 'available') {
            $this->bus->dispatch(new ChangeVehicleStatus($vehicleId, 'in_service'));
        }
    }

    // ---------------------------------------------------------------------------------------------
    // 3) Envíos y etiquetas
    // ---------------------------------------------------------------------------------------------

    /**
     * Recibe el envío por el dominio (bultos incluidos) con franja de entrega de hoy. Idempotente por
     * (fuente, id externo).
     *
     * @param array{source: string, external_id: string, customer_name: string, customer_document_id: ?string, customer_phone: string, customer_email: ?string, address_text: string, address_reference: ?string, lat: float, lng: float, window: array{0: string, 1: string}, lines: list<array{sku: string, product_name: string, quantity: int, image_url: string, price: float}>} $order
     */
    private function ensureShipment(array $order, ?string $destinationNodeId): Shipment
    {
        $source = OrderSource::from($order['source']);
        $reference = SourceReference::of($source, $order['external_id']);

        $existing = $this->shipments->findBySourceReference($reference);
        if ($existing !== null) {
            return $existing;
        }

        $address = Address::of(
            $order['address_text'],
            $order['address_reference'],
            ShipmentGeoPoint::of($order['lat'], $order['lng']),
        );

        $lines = [];
        foreach ($order['lines'] as $line) {
            $lines[] = ShipmentLine::create(
                ShipmentLineId::generate(),
                Sku::of($line['sku']),
                ProductName::of($line['product_name']),
                $line['quantity'],
                ProductImageUrl::fromNullable($line['image_url']),
                Money::fromNullable($line['price']),
            );
        }

        $shipment = Shipment::receive(
            $this->shipments->nextIdentity(),
            $source,
            $reference,
            Customer::of($order['customer_name'], $order['customer_document_id'], $order['customer_phone'], $order['customer_email']),
            $address,
            $this->scorer->score($address),
            $lines,
            $destinationNodeId,
        );
        $shipment->assignDeliverySlot(DeliverySlot::of($this->date, $order['window'][0], $order['window'][1]));

        $this->shipments->save($shipment);

        return $shipment;
    }

    /**
     * Emite las etiquetas QR firmadas del pedido (una por bulto) con el caso de uso real. Idempotente: si
     * el pedido ya tiene etiquetas vigentes, no reemite.
     */
    private function ensureLabels(Shipment $shipment, string $destination): void
    {
        $orderNumber = $shipment->sourceReference()->externalId;
        if (EmittedLabelRecord::query()->where('order_id', $orderNumber)->whereNull('revoked_at')->exists()) {
            return;
        }

        $lines = [];
        foreach ($shipment->lines() as $line) {
            $lines[] = new OrderLineInput($line->sku()->value, $line->productName()->value, $line->quantity());
        }

        try {
            $this->bus->dispatch(new EmitShipmentLabels($orderNumber, self::LABEL_ORIGIN, $destination, $lines));
        } catch (RuntimeException $exception) {
            // Sin LABELS_SIGNING_KEY en el entorno no se pueden firmar etiquetas: el escenario sigue sin ellas.
            $this->say('warn', "Etiquetas de {$orderNumber} no emitidas: {$exception->getMessage()}");
        }
    }

    /**
     * Pasa a PLANNED los envíos que entraron al plan publicado (solo los que siguen en RECEIVED).
     *
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

    // ---------------------------------------------------------------------------------------------
    // 4) Plan del día
    // ---------------------------------------------------------------------------------------------

    /**
     * Plan publicado del camión para hoy. Orden por el optimizador configurado; ETA = manejo + atención por
     * parada, desplazada para que la TERCERA parada caiga cerca de "ahora" (así la ruta queda en curso).
     * Geometría por calles calculada en el servidor (CD → paradas). Idempotente por (fecha, vehículo).
     *
     * @param list<Shipment> $shipments
     */
    private function ensurePlan(string $vehicleId, array $shipments, float $cdLat, float $cdLng): DeliveryPlan
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
            if ($point === null) {
                throw new RuntimeException("El envío {$id} no tiene coordenadas.");
            }
            $routeStops[] = PlanningRouteStop::of($id, $point->lat, $point->lng);
        }

        // Orden del optimizador (la primera parada queda fija como arranque del reparto).
        $optimized = $this->optimizer->optimize($this->now, $routeStops);

        // Minutos desde la salida del CD: tramo CD → 1ra parada + manejo del optimizador + atención.
        $first = $byId[$optimized[0]->shipmentId]->address()->geoPoint;
        assert($first !== null);
        $cdLegMinutes = (int) ceil(PlanningGeoPoint::of($cdLat, $cdLng)->haversineMeters(
            PlanningGeoPoint::of($first->lat, $first->lng),
        ) / 1000 / 25 * 60) + 10;

        $offsets = [];
        foreach ($optimized as $index => $stop) {
            $offsets[$stop->shipmentId] = $cdLegMinutes + $stop->eta->minutesFromDeparture + self::SERVICE_MINUTES * $index;
        }

        $departAt = $this->departureFor($offsets[$optimized[self::COMPLETED_STOPS]->shipmentId ?? $optimized[0]->shipmentId]);

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

        // Geometría: CD seguido de las paradas en orden de reparto.
        $points = [PlanningGeoPoint::of($cdLat, $cdLng)];
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

    /**
     * Hora de salida del CD para que la parada actual (offset dado) caiga ~10 minutos después de ahora,
     * acotada a la jornada (07:00–16:30) y redondeada a 5 minutos.
     */
    private function departureFor(int $currentStopOffsetMinutes): DateTimeImmutable
    {
        $target = $this->now->modify('+10 minutes')->modify("-{$currentStopOffsetMinutes} minutes");
        $earliest = new DateTimeImmutable($this->date.' 07:00:00', $this->tz);
        $latest = new DateTimeImmutable($this->date.' 16:30:00', $this->tz);

        $depart = $target < $earliest ? $earliest : ($target > $latest ? $latest : $target);
        $minute = (int) $depart->format('i');

        return $depart->setTime((int) $depart->format('H'), $minute - ($minute % 5), 0);
    }

    // ---------------------------------------------------------------------------------------------
    // 5) Ruta del chofer, entregas y tracking
    // ---------------------------------------------------------------------------------------------

    /**
     * Ruta del día armada desde el plan publicado (secuencia + foto del destino + ETA + coordenadas), con
     * las primeras paradas cerradas. Copia chofer y ayudantes de la tripulación a la ruta (columnas
     * aditivas). Idempotente por (vehículo, fecha).
     *
     * @param list<Shipment> $shipments
     */
    private function ensureRoute(string $vehicleId, DeliveryPlan $plan, array $shipments, CrewModel $crew): VehicleRoute
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

        // Alineación con la tripulación (mismo criterio que ExecutionDemoSeeder).
        $record = VehicleRouteRecord::query()->where('id', $route->id()->toString())->first();
        if ($record !== null) {
            $record->driver_id = $crew->driver_id;
            $record->helper_ids = $crew->helper_ids;
            $record->save();
        }

        return $route;
    }

    /**
     * Paradas del plan ordenadas por secuencia.
     *
     * @return list<PlannedStop>
     */
    private function planStops(DeliveryPlan $plan): array
    {
        $stops = array_values($plan->stops());
        usort($stops, static fn (PlannedStop $a, PlannedStop $b): int => $a->sequence()->value <=> $b->sequence()->value);

        return $stops;
    }

    /**
     * Hora de cierre de una parada ya entregada: su ETA + 4 min, sin pasar de "ahora" (con margen) para
     * que las entregas cerradas queden en el pasado y en orden.
     */
    private function settledAt(?DateTimeImmutable $eta, int $index): DateTimeImmutable
    {
        $cap = $this->now->modify('-'.((self::COMPLETED_STOPS - $index) * 12).' minutes');
        $candidate = ($eta ?? $cap)->setTimezone($this->tz)->modify('+4 minutes');

        return $candidate < $cap ? $candidate : $cap;
    }

    /**
     * Entregas de cada parada por el agregado (preparación → carga en el CD; las cerradas con POD) y su
     * proyección de seguimiento (envío + bultos + posición en la ruta). Idempotente por envío.
     *
     * @param list<Shipment> $shipments
     */
    private function ensureRouteDeliveries(string $vehicleId, VehicleRoute $route, array $shipments, User $chofer): void
    {
        $byId = [];
        foreach ($shipments as $shipment) {
            $byId[$shipment->id()->toString()] = $shipment;
        }

        $routeStops = $route->stops();
        $loadedAt = $route->stops()[0]->etaAt() !== null
            ? $this->dayStart()->setTime(7, 0)
            : $this->now->modify('-3 hours');
        $device = DeviceContext::of(self::DEVICE_ID, (string) $chofer->getKey());

        foreach ($routeStops as $index => $stop) {
            $shipmentId = $stop->shipmentId();
            if ($this->deliveries->findByShipmentId($shipmentId) !== null) {
                continue;
            }

            $units = $this->unitIds($shipmentId);
            $key = "scenario-{$this->date}-{$shipmentId}";
            $cdGeo = ExecutionGeoPoint::of(-17.7490, -63.1540);

            $delivery = Delivery::start($this->deliveries->nextIdentity(), $shipmentId, $vehicleId);
            foreach ($units as $u => $unitId) {
                $delivery->recordPreparationScan($unitId, $cdGeo, $device, $loadedAt->modify("+{$u} minutes"), "{$key}-prep-{$u}");
            }
            foreach ($units as $u => $unitId) {
                $delivery->recordLoadScan($unitId, $cdGeo, $device, $loadedAt->modify('+'.(30 + $u).' minutes'), "{$key}-load-{$u}");
            }

            $settled = $stop->settledAt();
            if ($settled !== null) {
                $shipment = $byId[$shipmentId] ?? null;
                $geo = ExecutionGeoPoint::of($stop->lat() ?? -17.78, $stop->lng() ?? -63.18);
                $delivery->recordProofOfDelivery(
                    $units[0] ?? $shipmentId,
                    ProofOfDelivery::of(
                        'FIRMA-'.strtoupper(substr(md5($shipmentId), 0, 8)).($shipment !== null ? ' '.$shipment->customerName() : ''),
                        null,
                        $settled,
                    ),
                    $geo,
                    $device,
                    $settled,
                    "{$key}-pod",
                );
            }

            $this->deliveries->save($delivery);

            // Seguimiento: abre el envío, lo ubica en la ruta y proyecta los hechos de ejecución.
            $this->bus->dispatch(new OpenTracking($shipmentId, max(0, $stop->sequence() - 1)));
            $this->bus->dispatch(new PlaceShipmentOnRoute($shipmentId, $route->id()->toString(), $stop->sequence()));
            $this->bus->dispatch(new ProjectExecutionEvent('execution.shipment_loaded', $shipmentId));
            foreach ($units as $unitId) {
                $this->bus->dispatch(new ProjectUnitExecutionEvent('execution.shipment_prepared', $shipmentId, $unitId, $loadedAt->format(DateTimeInterface::ATOM), self::CD_CODE));
                $this->bus->dispatch(new ProjectUnitExecutionEvent('execution.shipment_loaded', $shipmentId, $unitId, $loadedAt->modify('+30 minutes')->format(DateTimeInterface::ATOM), self::CD_CODE));
            }
            if ($settled !== null) {
                $this->bus->dispatch(new ProjectExecutionEvent('execution.shipment_delivered', $shipmentId));
                $this->bus->dispatch(new ProjectUnitExecutionEvent('execution.shipment_delivered', $shipmentId, '', $settled->format(DateTimeInterface::ATOM)));
            }

            unset($index);
        }
    }

    /**
     * Ids de los bultos físicos del envío (shipment_units), en orden estable.
     *
     * @return list<string>
     */
    private function unitIds(string $shipmentId): array
    {
        /** @var list<string> $ids */
        $ids = PhysicalUnitRecord::query()
            ->where('shipment_id', $shipmentId)
            ->orderBy('line_id')
            ->orderBy('unit_index')
            ->orderBy('piece_index')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return $ids;
    }

    // ---------------------------------------------------------------------------------------------
    // 6) Telemetría y tienda
    // ---------------------------------------------------------------------------------------------

    /**
     * Recorrido GPS del camión desde el CD por las paradas cerradas hasta acercarse a la parada actual,
     * con el último ping a ~1 minuto de "ahora" (el tablero del supervisor lo ve en vivo). Usa la ingesta
     * real (RecordVehiclePings: pings + trip + posición en vivo). Idempotente por (vehículo, día, GPS).
     */
    private function ensureTelemetry(string $vehicleId, string $driverId, VehicleRoute $route, float $cdLat, float $cdLng): void
    {
        $already = PingRecord::query()
            ->where('vehicle_id', $vehicleId)
            ->where('device_id', self::GPS_DEVICE_ID)
            ->where('recorded_at', '>=', $this->dayStart())
            ->exists();
        if ($already) {
            return;
        }

        $waypoints = [[$cdLat, $cdLng]];
        $stops = $route->stops();
        foreach (array_slice($stops, 0, self::COMPLETED_STOPS) as $stop) {
            if ($stop->lat() !== null && $stop->lng() !== null) {
                $waypoints[] = [$stop->lat(), $stop->lng()];
            }
        }
        $current = $stops[self::COMPLETED_STOPS] ?? null;
        $last = $waypoints[count($waypoints) - 1];
        if ($current !== null && $current->lat() !== null && $current->lng() !== null) {
            // A un 75% del tramo hacia la parada actual: "llegando".
            $waypoints[] = [
                $last[0] + ($current->lat() - $last[0]) * 0.75,
                $last[1] + ($current->lng() - $last[1]) * 0.75,
            ];
        }

        $trail = [];
        $count = count($waypoints);
        for ($i = 1; $i < $count; $i++) {
            [$fromLat, $fromLng] = $waypoints[$i - 1];
            [$toLat, $toLng] = $waypoints[$i];
            for ($step = ($i === 1 ? 0 : 1); $step <= 12; $step++) {
                $f = $step / 12;
                $trail[] = [$fromLat + ($toLat - $fromLat) * $f, $fromLng + ($toLng - $fromLng) * $f];
            }
        }

        $start = $stops[0]->etaAt()?->setTimezone($this->tz)->modify('-30 minutes') ?? $this->now->modify('-2 hours');
        $end = $this->now->modify('-1 minute');
        if ($start >= $end) {
            $start = $end->modify('-90 minutes');
        }
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
                'device_id' => self::GPS_DEVICE_ID,
            ];
            $previous = [$lat, $lng, $at];
        }

        $this->bus->dispatch(new RecordVehiclePings($vehicleId, $driverId, $route->id()->toString(), 'app', $pings));
    }

    /**
     * Tienda: (a) un traspaso CD → tienda que llegó hoy con la recepción ABIERTA esperando el escaneo de
     * bultos; (b) un "Recojo en tienda" ya recibido y conciliado, listo para entregar en mostrador.
     */
    private function ensureStoreFlows(
        string $transferVehicleId,
        NodeRecord $cd,
        NodeRecord $store,
        Shipment $openReception,
        Shipment $readyPickup,
        User $tienda,
    ): void {
        $storeId = (string) $store->id;
        $storeGeo = ExecutionGeoPoint::of((float) $store->lat, (float) $store->lng);
        $cdGeo = ExecutionGeoPoint::of((float) $cd->lat, (float) $cd->lng);
        $driverDevice = DeviceContext::of(self::DEVICE_ID, 'transfer-driver');
        $morning = $this->dayStart()->setTime(7, 15);

        foreach ([$openReception, $readyPickup] as $n => $shipment) {
            $shipmentId = $shipment->id()->toString();
            $units = $this->unitIds($shipmentId);
            $key = "scenario-{$this->date}-{$shipmentId}";

            if ($this->deliveries->findByShipmentId($shipmentId) === null) {
                $delivery = Delivery::start($this->deliveries->nextIdentity(), $shipmentId, $transferVehicleId);
                foreach ($units as $u => $unitId) {
                    $delivery->recordPreparationScan($unitId, $cdGeo, $driverDevice, $morning->modify("+{$u} minutes"), "{$key}-prep-{$u}");
                }
                foreach ($units as $u => $unitId) {
                    $delivery->recordLoadScan($unitId, $cdGeo, $driverDevice, $morning->modify('+'.(20 + $u).' minutes'), "{$key}-load-{$u}");
                }
                $arrival = $morning->modify('+'.(75 + 20 * $n).' minutes');
                foreach ($units as $u => $unitId) {
                    $delivery->recordUnloadScan($storeId, $unitId, $storeGeo, $driverDevice, $arrival->modify("+{$u} minutes"), "{$key}-unload-{$u}");
                }
                $this->deliveries->save($delivery);

                $this->bus->dispatch(new OpenTracking($shipmentId, 0));
                $this->bus->dispatch(new ProjectExecutionEvent('execution.shipment_loaded', $shipmentId));
                foreach ($units as $unitId) {
                    $this->bus->dispatch(new ProjectUnitExecutionEvent('execution.shipment_loaded', $shipmentId, $unitId, $morning->modify('+20 minutes')->format(DateTimeInterface::ATOM), self::CD_CODE));
                    $this->bus->dispatch(new ProjectUnitExecutionEvent('execution.shipment_unloaded', $shipmentId, $unitId, $arrival->format(DateTimeInterface::ATOM), self::STORE_CODE));
                }

                // Recepción en la tienda (idempotente por envío + nodo en el handler).
                $this->bus->dispatch(new OpenNodeReception(
                    $shipmentId,
                    (string) $cd->id,
                    $storeId,
                    'CD',
                    'STORE',
                    $units,
                    $arrival->format(DateTimeInterface::ATOM),
                ));

                // El segundo ya se recibió y concilió: queda listo para el recojo del cliente.
                if ($n === 1) {
                    foreach ($units as $u => $unitId) {
                        $this->bus->dispatch(new ReceiveUnitAtNode(
                            $shipmentId,
                            $storeId,
                            $unitId,
                            (float) $store->lat,
                            (float) $store->lng,
                            self::DEVICE_ID,
                            (string) $tienda->getKey(),
                            $arrival->modify('+'.(10 + $u).' minutes')->format(DateTimeInterface::ATOM),
                            "{$key}-receive-{$u}",
                        ));
                        $this->bus->dispatch(new ProjectUnitExecutionEvent('execution.node_reception_recorded', $shipmentId, $unitId, $arrival->modify('+10 minutes')->format(DateTimeInterface::ATOM), self::STORE_CODE));
                    }
                    $this->bus->dispatch(new ReconcileNodeReception(
                        $shipmentId,
                        $storeId,
                        self::DEVICE_ID,
                        (string) $tienda->getKey(),
                        $arrival->modify('+20 minutes')->format(DateTimeInterface::ATOM),
                    ));
                }
            }
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Datos
    // ---------------------------------------------------------------------------------------------

    /**
     * Pedidos de reparto a domicilio en barrios reales de Santa Cruz de la Sierra. La primera es la más
     * cercana al CD (el optimizador la deja fija como arranque); el resto lo ordena el motor de rutas.
     *
     * @return list<array{source: string, external_id: string, customer_name: string, customer_document_id: ?string, customer_phone: string, customer_email: ?string, address_text: string, address_reference: ?string, lat: float, lng: float, window: array{0: string, 1: string}, lines: list<array{sku: string, product_name: string, quantity: int, image_url: string, price: float}>}>
     */
    private function customerOrders(): array
    {
        $tag = $this->now->format('ymd');

        return [
            $this->order('SAP', "SCZ-{$tag}-01", 'Marcelo Saucedo Rivero', '7812345 SC', '+591 77012345', 'marcelo.saucedo@gmail.com',
                'Av. Hugo Banzer entre 3er y 4to anillo, calle Los Cusis 18, Santa Cruz de la Sierra',
                'Casa esquina con rejas blancas, frente al surtidor', -17.7465, -63.1712, ['08:00', '12:00'],
                [['COL-QUEEN-ORT', 'Colchón ortopédico Queen', 1, 2890.00], ['BAS-QUEEN', 'Base box Queen', 1, 1450.00]]),
            $this->order('MAGENTO', "SCZ-{$tag}-02", 'Valeria Rojas Suárez', '6543219 SC', '+591 71634512', 'valeria.rojas@hotmail.com',
                'Av. San Martín 600, Barrio Equipetrol, Santa Cruz de la Sierra',
                'Edificio Torre Equipetrol, piso 7, oficina 7B (dejar en recepción si no está)', -17.7633, -63.1966, ['09:00', '13:00'],
                [['TV-SAMS-QN55', 'Televisor Samsung 55" QLED 4K', 1, 6990.00]]),
            $this->order('SAP', "SCZ-{$tag}-03", 'Gabriela Méndez Antelo', '8123456 SC', '+591 75566778', 'gabriela.mendez@gmail.com',
                'Av. Irala 2do anillo N° 452, Santa Cruz de la Sierra',
                'Edificio Los Tajibos, depto 3A, timbre del medio', -17.7898, -63.1868, ['09:00', '14:00'],
                [['LAP-HP-15', 'Laptop HP 15" Core i5', 1, 5490.00], ['MOU-LOG-M170', 'Mouse inalámbrico Logitech', 1, 120.00]]),
            $this->order('STORE', "SCZ-{$tag}-04", 'Rodrigo Añez Paz', '5698741 SC', '+591 76321458', 'rodrigo.anez@yahoo.com',
                'Calle Las Lilas 245, Barrio Urbarí, Santa Cruz de la Sierra',
                'Portón café de madera, perro bravo: llamar antes de tocar', -17.7958, -63.2005, ['10:00', '15:00'],
                [['LAV-LG-12', 'Lavadora LG 12 kg carga frontal', 1, 4790.00]]),
            $this->order('MAGENTO', "SCZ-{$tag}-05", 'Carolina Justiniano Vaca', '4789632 SC', '+591 70851236', 'carolina.justiniano@gmail.com',
                'Av. Roca y Coronado 3er anillo externo, Barrio Las Palmas, Santa Cruz de la Sierra',
                'Condominio Las Palmas, casa 14 (avisar en garita)', -17.7987, -63.2128, ['11:00', '16:00'],
                [['REF-MAB-400', 'Refrigerador Mabe 400 L', 1, 5290.00], ['MIC-SAMS-23', 'Microondas Samsung 23 L', 1, 890.00]]),
            $this->order('SAP', "SCZ-{$tag}-06", 'Wilfredo Vaca Rocha', '3987456 SC', '+591 72198364', null,
                'Av. Paurito UV 160, Manzana 8, Plan 3000, Santa Cruz de la Sierra',
                'A media cuadra del mercado Plan 3000, tienda de barrio "Doña Elsa"', -17.8281, -63.1414, ['12:00', '17:00'],
                [['VEN-PIE-18', 'Ventilador de pie 18"', 2, 390.00], ['LIC-OST-600', 'Licuadora Oster 600 W', 1, 650.00]]),
            $this->order('EXCEL', "SCZ-{$tag}-07", 'Juana Choque Mamani', '9012345 SC', '+591 69032147', null,
                'Av. Principal Villa 1ro de Mayo, UV 98, Manzana 12, Santa Cruz de la Sierra',
                'Casa de dos plantas color amarillo, al lado de la farmacia', -17.7588, -63.1249, ['13:00', '18:00'],
                [['COC-IND-4H', 'Cocina 4 hornallas con horno', 1, 2190.00], ['GAR-GLP-10', 'Garrafa GLP 10 kg (envase)', 1, 280.00]]),
        ];
    }

    /**
     * "Recojo en tienda": destino = nodo de la tienda (Tienda Santa Cruz Norte).
     *
     * @return list<array{source: string, external_id: string, customer_name: string, customer_document_id: ?string, customer_phone: string, customer_email: ?string, address_text: string, address_reference: ?string, lat: float, lng: float, window: array{0: string, 1: string}, lines: list<array{sku: string, product_name: string, quantity: int, image_url: string, price: float}>}>
     */
    private function storeOrders(string $storeNodeId): array
    {
        $tag = $this->now->format('ymd');
        $store = NodeRecord::query()->where('id', $storeNodeId)->first();
        $lat = $store !== null ? (float) $store->lat : -17.7310;
        $lng = $store !== null ? (float) $store->lng : -63.1710;

        return [
            $this->order('MAGENTO', "SCZ-{$tag}-T1", 'Daniel Ortiz Cuéllar', '6321458 SC', '+591 73344556', 'daniel.ortiz@gmail.com',
                'Recojo en tienda — Tienda Santa Cruz Norte, Av. Banzer 3er anillo, Santa Cruz de la Sierra',
                'Retira el titular con CI', $lat, $lng, ['15:00', '20:00'],
                [['CEL-SAMS-A55', 'Celular Samsung Galaxy A55 256 GB', 1, 3490.00], ['AUD-JBL-T520', 'Audífonos JBL Tune 520BT', 1, 450.00]]),
            $this->order('SAP', "SCZ-{$tag}-T2", 'Lucía Paz Moreno', '7456321 SC', '+591 78965412', 'lucia.paz@hotmail.com',
                'Recojo en tienda — Tienda Santa Cruz Norte, Av. Banzer 3er anillo, Santa Cruz de la Sierra',
                'Retira su esposo, Jorge Paz (autorizado)', $lat, $lng, ['10:00', '20:00'],
                [['FRE-PHI-AIR', 'Freidora de aire Philips 4,1 L', 1, 990.00]]),
        ];
    }

    /**
     * @param  array{0: string, 1: string}                                                                                                                                                                                                                                                                                                                                             $window
     * @param  list<array{0: string, 1: string, 2: int, 3: float}>                                                                                                                                                                                                                                                                                                                     $lines
     * @return array{source: string, external_id: string, customer_name: string, customer_document_id: ?string, customer_phone: string, customer_email: ?string, address_text: string, address_reference: ?string, lat: float, lng: float, window: array{0: string, 1: string}, lines: list<array{sku: string, product_name: string, quantity: int, image_url: string, price: float}>}
     */
    private function order(
        string $source,
        string $externalId,
        string $customer,
        ?string $document,
        string $phone,
        ?string $email,
        string $address,
        ?string $reference,
        float $lat,
        float $lng,
        array $window,
        array $lines,
    ): array {
        $mapped = [];
        foreach ($lines as [$sku, $name, $quantity, $price]) {
            $mapped[] = [
                'sku' => $sku,
                'product_name' => $name,
                'quantity' => $quantity,
                'image_url' => 'https://picsum.photos/seed/'.strtolower($sku).'/200/200',
                'price' => $price,
            ];
        }

        return [
            'source' => $source,
            'external_id' => $externalId,
            'customer_name' => $customer,
            'customer_document_id' => $document,
            'customer_phone' => $phone,
            'customer_email' => $email,
            'address_text' => $address,
            'address_reference' => $reference,
            'lat' => $lat,
            'lng' => $lng,
            'window' => $window,
            'lines' => $mapped,
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Utilidades
    // ---------------------------------------------------------------------------------------------

    private function node(string $code): NodeRecord
    {
        $node = NodeRecord::query()->where('code', $code)->first();
        if ($node === null) {
            throw new RuntimeException("Falta el nodo {$code} (correr NetworkSeeder).");
        }

        return $node;
    }

    private function vehicle(string $plate): VehicleModel
    {
        $vehicle = VehicleModel::query()->where('plate', $plate)->first();
        if ($vehicle === null) {
            throw new RuntimeException("Falta el vehículo {$plate} (correr FleetSeeder).");
        }

        return $vehicle;
    }

    private function dayStart(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->date.' 00:00:00', $this->tz);
    }

    private function say(string $level, string $message): void
    {
        if ($this->command === null) {
            return;
        }

        $level === 'warn' ? $this->command->warn($message) : $this->command->info($message);
    }

    /**
     * @param array<string, User> $users
     */
    private function summary(
        array $users,
        VehicleModel $vehicle,
        CrewModel $crew,
        DeliveryPlan $plan,
        VehicleRoute $route,
        Shipment $openReception,
        Shipment $readyPickup,
    ): void {
        $this->say('info', "Escenario de hoy ({$this->date}, Santa Cruz) listo.");
        foreach ($users as $key => $user) {
            $this->say('info', sprintf('  usuario %-14s %s (id %s)', $key, (string) $user->email, (string) $user->getKey()));
        }
        $this->say('info', sprintf('  vehículo %s id=%s | tripulación %s', (string) $vehicle->plate, (string) $vehicle->id, (string) $crew->id));
        $this->say('info', sprintf('  plan %s (%s) | ruta %s (%s)', $plan->id()->toString(), $plan->status()->value, $route->id()->toString(), $route->status()->value));
        foreach ($route->stops() as $stop) {
            $this->say('info', sprintf(
                '    #%d %-8s %s — %s (eta %s)',
                $stop->sequence(),
                $stop->status()->value,
                (string) $stop->customerName(),
                (string) $stop->address(),
                $stop->etaAt()?->setTimezone($this->tz)->format('H:i') ?? '-',
            ));
        }
        $this->say('info', '  parada actual: '.($route->currentStop()?->shipmentId() ?? '-'));
        $this->say('info', '  tienda: recepción abierta '.$openReception->id()->toString().' | recojo listo '.$readyPickup->id()->toString());
    }
}
