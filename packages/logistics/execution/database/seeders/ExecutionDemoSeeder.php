<?php

declare(strict_types=1);

namespace Logistics\Execution\Database\Seeders;

use DateTimeImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\Seeder;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Execution\Domain\Model\RouteStop;
use Logistics\Execution\Domain\Model\VehicleRoute;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\FailureReason;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Execution\Domain\ValueObject\ProofOfDelivery;
use Logistics\Execution\Domain\ValueObject\RouteDate;
use Logistics\Execution\Domain\ValueObject\RouteStopId;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\VehicleRouteRecord;
use Logistics\Fleet\Application\Command\AssembleCrew\AssembleCrew;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\CrewModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\DriverModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\HelperModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Seeder de demo del contexto Ejecución.
 *
 * Construye entregas realistas a través del agregado (no insertando filas sueltas) para respetar la
 * máquina de estados y las invariantes de los escaneos (append-only, idempotentes), y las persiste por
 * el repositorio. Deja una muestra de cada desenlace: en tránsito, entregada y fallida.
 *
 * También arma una ruta del chofer de demostración —la secuencia ordenada de paradas de un camión— y la
 * hace avanzar un par de paradas, para que el panel muestre el flujo "deja una y procede con la siguiente".
 *
 * No es el DatabaseSeeder raíz: el integrador lo encola desde ahí. Corre solo en entornos de demo.
 */
final class ExecutionDemoSeeder extends Seeder
{
    /**
     * Centro de distribución (CD) de La Paz: punto de salida del camión. La traza GPS arranca acá y el
     * orden de reparto se calcula por cercanía desde este punto.
     */
    private const CD_LAT = -16.4939;

    private const CD_LNG = -68.1500;

    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly VehicleRouteRepository $routes,
    ) {}

    public function run(): void
    {
        // Idempotente: si ya hay entregas de demo sembradas, no se vuelve a sembrar la muestra (las
        // entregas de muestra usan shipment_id único y un segundo run rompería esa restricción). El
        // contexto Ejecución es dueño exclusivo de execution_deliveries, así que basta con que la tabla
        // tenga filas. La alineación de tripulaciones corre SIEMPRE más abajo, también sobre bases ya
        // sembradas.
        if (!DeliveryRecord::query()->exists()) {
            // IDs reales ya sembrados (upstream): cada entrega referencia un envío distinto (la tabla
            // execution_deliveries exige shipment_id único) y un vehículo válido de la flota. Si faltan
            // datos reales, se cae en uuids generados para no romper el seed.
            $vehicleIds = $this->vehicleIds();
            $nodeId = $this->nodeId();

            // Ruta de demo del chofer: pedidos de reparto DENTRO de La Paz (un solo departamento),
            // ordenados por cercanía. Se resuelve PRIMERO para excluir sus pedidos de las entregas de
            // muestra y así no violar la unicidad de shipment_id en execution_deliveries.
            $routeVehicleId = $vehicleIds[0] ?? Uuid::generate()->value;
            $routeShipments = $this->laPazRouteShipments();
            $routeShipmentIds = array_map(static fn (ShipmentRecord $shipment): string => (string) $shipment->id, $routeShipments);

            // Entregas de muestra (un desenlace de cada tipo) con pedidos que NO son de la ruta del chofer.
            $sampleShipmentIds = array_values(array_filter(
                $this->shipmentIds(),
                static fn (string $id): bool => !in_array($id, $routeShipmentIds, true),
            ));

            $this->deliveries->save($this->deliveredSample($sampleShipmentIds[0] ?? Uuid::generate()->value, $vehicleIds[0] ?? Uuid::generate()->value));
            $this->deliveries->save($this->inTransitSample($sampleShipmentIds[1] ?? Uuid::generate()->value, $vehicleIds[1] ?? Uuid::generate()->value, $nodeId));
            $this->deliveries->save($this->failedSample($sampleShipmentIds[2] ?? Uuid::generate()->value, $vehicleIds[2] ?? Uuid::generate()->value));

            $this->seedDriverRoute($routeVehicleId, $routeShipments);
        }

        // Alineación idempotente flota ↔ ejecución: para cada ruta del día, garantiza que exista una
        // tripulación real (fleet_crews) con su MISMO (vehicle_id, fecha) y copia chofer + ayudantes a las
        // columnas aditivas de la ruta. Sin esto, el join (vehicle_id, route_date == assembled_for) del
        // historial operativo del chofer no cruza y el panel sale en cero. Corre siempre, incluso cuando
        // las entregas ya estaban sembradas (Fleet se siembra antes que Ejecución en el DatabaseSeeder).
        $this->alignDriverRoutesWithCrews();
    }

    /**
     * Alinea las rutas de reparto con las tripulaciones de flota para que el historial operativo del
     * chofer cruce datos. Para cada `execution_driver_routes` (vehículo, fecha): si no hay un
     * `fleet_crews` con ese mismo (vehicle_id, assembled_for), arma uno con un chofer real —habilitado
     * para el vehículo si se puede, si no cualquiera activo— y 1–2 ayudantes reales, vía el comando de
     * dominio AssembleCrew (Fleet es dueño de fleet_crews). Luego puebla `driver_id` y `helper_ids` de la
     * ruta desde ese crew con un UPDATE idempotente (columnas aditivas nullable).
     *
     * Re-ejecutable sin duplicar: la existencia previa de crew por (vehículo, fecha) actúa de guardia y
     * el UPDATE deja los mismos valores.
     */
    private function alignDriverRoutesWithCrews(): void
    {
        /** @var list<VehicleRouteRecord> $routes */
        $routes = VehicleRouteRecord::query()->get()->all();
        if ($routes === []) {
            return;
        }

        $bus = Container::getInstance()->make(CommandBus::class);
        assert($bus instanceof CommandBus);

        foreach ($routes as $route) {
            $vehicleId = (string) $route->vehicle_id;
            $date = substr((string) $route->route_date, 0, 10);

            $crew = $this->crewFor($vehicleId, $date);
            if ($crew === null) {
                $crew = $this->assembleCrewFor($bus, $vehicleId, $date);
            }

            // Sin choferes reales no se puede alinear: se deja la ruta tal cual (no se inventa un chofer).
            if ($crew === null) {
                continue;
            }

            // UPDATE idempotente de las columnas aditivas nullable (chofer + ayudantes de la tripulación).
            $route->driver_id = $crew->driver_id;
            $route->helper_ids = $crew->helper_ids;
            $route->save();
        }
    }

    /**
     * Tripulación existente para ese vehículo y día (clave de negocio del join), o null si todavía no hay.
     */
    private function crewFor(string $vehicleId, string $date): ?CrewModel
    {
        return CrewModel::query()
            ->where('vehicle_id', $vehicleId)
            ->whereDate('assembled_for', $date)
            ->first();
    }

    /**
     * Arma una tripulación real para (vehículo, fecha) vía el comando de dominio AssembleCrew y devuelve
     * el registro resultante. Devuelve null si no hay ningún chofer real que asignar.
     */
    private function assembleCrewFor(CommandBus $bus, string $vehicleId, string $date): ?CrewModel
    {
        $driverId = $this->driverForVehicle($vehicleId);
        if ($driverId === null) {
            return null;
        }

        $bus->dispatch(new AssembleCrew($driverId, $this->availableHelperIds(), $vehicleId, $date));

        return $this->crewFor($vehicleId, $date);
    }

    /**
     * Elige un chofer real para el vehículo: de preferencia uno activo habilitado para ese vehículo; si
     * no hay, cualquier chofer activo. Null si no hay choferes activos sembrados.
     */
    private function driverForVehicle(string $vehicleId): ?string
    {
        $enabled = DriverModel::query()
            ->where('active', true)
            ->whereJsonContains('enabled_vehicle_ids', $vehicleId)
            ->orderBy('id')
            ->value('id');
        if ($enabled !== null) {
            return (string) $enabled;
        }

        $any = DriverModel::query()->where('active', true)->orderBy('id')->value('id');

        return $any !== null ? (string) $any : null;
    }

    /**
     * Hasta dos ayudantes reales activos para acompañar la ruta.
     *
     * @return list<string>
     */
    private function availableHelperIds(): array
    {
        $ids = [];
        foreach (HelperModel::query()->where('active', true)->orderBy('id')->limit(2)->get(['id']) as $helper) {
            $ids[] = (string) $helper->id;
        }

        return $ids;
    }

    /**
     * Ruta del chofer de demo: paradas de reparto DENTRO de La Paz (un solo departamento), todas con
     * coordenadas y en ORDEN DE CERCANÍA (vecino más cercano desde el CD), con su referencia, cliente y
     * dirección. Se avanza la primera (entregada) y la segunda (fallida) para que el panel muestre
     * progreso y la "parada actual" como la siguiente pendiente. Luego siembra la traza GPS del recorrido.
     *
     * @param list<ShipmentRecord> $routeShipments
     */
    private function seedDriverRoute(string $vehicleId, array $routeShipments): void
    {
        $date = RouteDate::fromString('2026-10-13');

        // Idempotente: si ya se armó la ruta de ese camión y día, no se vuelve a crear.
        if ($this->routes->findByVehicleAndDate($vehicleId, $date) !== null) {
            return;
        }

        if ($routeShipments === []) {
            return;
        }

        $stops = [];
        foreach ($routeShipments as $index => $shipment) {
            $stops[] = RouteStop::plan(
                RouteStopId::generate(),
                (string) $shipment->id,
                $index + 1,
                $shipment->external_id,
                $shipment->customer_name,
                $shipment->address_text,
                new DateTimeImmutable('2026-10-13T'.sprintf('%02d', 9 + $index).':30:00+00:00'),
                $shipment->lat !== null ? (float) $shipment->lat : null,
                $shipment->lng !== null ? (float) $shipment->lng : null,
            );
        }

        $route = VehicleRoute::assemble($this->routes->nextIdentity(), $vehicleId, $date, $stops);
        $route->completeStop($stops[0]->shipmentId(), $this->at('10:05'));
        if (isset($stops[1])) {
            $route->failStop($stops[1]->shipmentId(), 'CUSTOMER_ABSENT', $this->at('10:50'));
        }

        $this->routes->save($route);

        // Traza GPS real del recorrido: breadcrumbs de tránsito CD → paradas en orden, dentro de La Paz.
        $this->seedTransitTrack($vehicleId, $routeShipments);
    }

    /**
     * Pedidos de reparto DENTRO de La Paz ciudad (un solo departamento), con coordenadas, en ORDEN DE
     * CERCANÍA desde el CD. La caja aproximada de La Paz/El Alto (lat -16.55..-16.47, lng -68.20..-68.10)
     * deja fuera pedidos de otros departamentos (Santa Cruz, Cochabamba) y los que no tienen coordenadas,
     * para que la ruta nunca mezcle departamentos ni arrastre paradas sin geocodificar.
     *
     * @return list<ShipmentRecord>
     */
    private function laPazRouteShipments(): array
    {
        /** @var list<ShipmentRecord> $shipments */
        $shipments = ShipmentRecord::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->whereBetween('lat', [-16.55, -16.47])
            ->whereBetween('lng', [-68.20, -68.10])
            ->orderBy('external_id')
            ->get()
            ->all();

        return $this->orderByNearest(self::CD_LAT, self::CD_LNG, $shipments);
    }

    /**
     * Ordena los pedidos por vecino más cercano arrancando en el CD: encadena siempre el pedido pendiente
     * más próximo al último elegido. Produce un reparto razonable y sin idas y vueltas absurdas.
     *
     * @param  list<ShipmentRecord> $shipments
     * @return list<ShipmentRecord>
     */
    private function orderByNearest(float $fromLat, float $fromLng, array $shipments): array
    {
        $remaining = $shipments;
        $ordered = [];
        $currentLat = $fromLat;
        $currentLng = $fromLng;

        while ($remaining !== []) {
            $bestKey = null;
            $bestDistance = INF;
            foreach ($remaining as $key => $shipment) {
                $distance = $this->haversineKm($currentLat, $currentLng, (float) $shipment->lat, (float) $shipment->lng);
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestKey = $key;
                }
            }

            if ($bestKey === null) {
                break;
            }

            $next = $remaining[$bestKey];
            $ordered[] = $next;
            $currentLat = (float) $next->lat;
            $currentLng = (float) $next->lng;
            unset($remaining[$bestKey]);
        }

        return $ordered;
    }

    /**
     * Siembra la traza GPS del recorrido real del camión: breadcrumbs de tránsito (escaneos IN_TRANSIT)
     * interpolados desde el CD y por cada parada en el orden de reparto, con hora creciente. Se cuelgan de
     * una entrega "ancla" (preparada y cargada en el CD) para que el mapa reconstruya "por dónde fue".
     *
     * @param list<ShipmentRecord> $routeShipments
     */
    private function seedTransitTrack(string $vehicleId, array $routeShipments): void
    {
        $anchor = $routeShipments[0] ?? null;
        if ($anchor === null) {
            return;
        }

        // Secuencia de posiciones: arranca en el CD y pasa por cada parada en el orden de reparto.
        $waypoints = [[self::CD_LAT, self::CD_LNG]];
        foreach ($routeShipments as $shipment) {
            $waypoints[] = [(float) $shipment->lat, (float) $shipment->lng];
        }

        $trail = $this->interpolateTrail($waypoints);

        // El camión se preparó y cargó en el CD y luego salió a repartir: la entrega ancla lleva la traza.
        $delivery = Delivery::start($this->deliveries->nextIdentity(), (string) $anchor->id, $vehicleId);
        $unit = 'U-ROUTE-01';
        $delivery->recordPreparationScan($unit, GeoPoint::of(self::CD_LAT, self::CD_LNG), $this->device(), $this->at('08:15'), Uuid::generate()->value);
        $delivery->recordLoadScan($unit, GeoPoint::of(self::CD_LAT, self::CD_LNG), $this->device(), $this->at('08:30'), Uuid::generate()->value);

        // Breadcrumbs de tránsito: uno cada 4 minutos desde las 08:40, en secuencia creciente.
        $base = new DateTimeImmutable('2026-10-13T08:40:00+00:00');
        $step = 0;
        foreach ($trail as $point) {
            $occurredAt = $base->modify('+'.($step * 4).' minutes');
            $delivery->recordTransitPing($unit, GeoPoint::of($point[0], $point[1]), $this->device(), $occurredAt, Uuid::generate()->value);
            $step++;
        }

        $this->deliveries->save($delivery);
    }

    /**
     * Interpola una traza densa a partir de la secuencia de posiciones (CD y paradas): añade puntos
     * intermedios en cada tramo para simular el desplazamiento por la ciudad. Devuelve >= 8 puntos.
     *
     * @param  list<array{0: float, 1: float}> $waypoints
     * @return list<array{0: float, 1: float}>
     */
    private function interpolateTrail(array $waypoints): array
    {
        if ($waypoints === []) {
            return [];
        }

        $trail = [$waypoints[0]];
        $count = count($waypoints);

        for ($i = 1; $i < $count; $i++) {
            [$fromLat, $fromLng] = $waypoints[$i - 1];
            [$toLat, $toLng] = $waypoints[$i];
            foreach ([0.34, 0.67, 1.0] as $fraction) {
                $trail[] = [
                    $fromLat + ($toLat - $fromLat) * $fraction,
                    $fromLng + ($toLng - $fromLng) * $fraction,
                ];
            }
        }

        return $trail;
    }

    /**
     * Distancia en kilómetros entre dos puntos por la fórmula de haversine (gran círculo). Alcanza para
     * comparar cercanía entre paradas y decidir el siguiente salto del recorrido.
     */
    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;

        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $h = sin($deltaLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($deltaLng / 2) ** 2;

        return 2 * $earthRadiusKm * asin(min(1.0, sqrt($h)));
    }

    /**
     * IDs reales de envíos (ordenados por external_id) para enlazar cada entrega con un envío existente.
     *
     * @return list<string>
     */
    private function shipmentIds(): array
    {
        /** @var list<string> $ids */
        $ids = ShipmentRecord::query()->orderBy('external_id')->pluck('id')->all();

        return $ids;
    }

    /**
     * IDs reales de vehículos (ordenados por placa) para enlazar cada entrega con un vehículo existente.
     *
     * @return list<string>
     */
    private function vehicleIds(): array
    {
        /** @var list<string> $ids */
        $ids = VehicleModel::query()->orderBy('plate')->pluck('id')->all();

        return $ids;
    }

    /**
     * Un nodo real de Network (ordenado por código) para la descarga/recepción del envío en ruta; si
     * Network todavía no tiene datos, cae en un uuid generado para no romper el seed.
     */
    private function nodeId(): string
    {
        $id = NodeRecord::query()->orderBy('code')->value('id');

        return $id !== null ? (string) $id : Uuid::generate()->value;
    }

    /**
     * Entrega completada: preparación → carga → prueba de entrega.
     */
    private function deliveredSample(string $shipmentId, string $vehicleId): Delivery
    {
        $delivery = Delivery::start($this->deliveries->nextIdentity(), $shipmentId, $vehicleId);
        $unit = 'U-10001';

        $delivery->recordPreparationScan($unit, $this->geo(), $this->device(), $this->at('09:00'), Uuid::generate()->value);
        $delivery->recordLoadScan($unit, $this->geo(), $this->device(), $this->at('09:30'), Uuid::generate()->value);
        $delivery->recordProofOfDelivery(
            $unit,
            ProofOfDelivery::of('QR-OK-DEMO-001', 'https://cdn.example/pod/demo-001.jpg', $this->at('11:15')),
            $this->geo(),
            $this->device(),
            $this->at('11:15'),
            Uuid::generate()->value,
        );

        return $delivery;
    }

    /**
     * Entrega en ruta con una descarga guiada y una recepción en nodo: preparación → carga → descarga
     * → recepción en nodo. El nodo es un nodo REAL de Network (CD/tienda ya sembrado) para que la
     * descarga y la recepción no referencien un nodo inexistente.
     */
    private function inTransitSample(string $shipmentId, string $vehicleId, string $nodeId): Delivery
    {
        $delivery = Delivery::start($this->deliveries->nextIdentity(), $shipmentId, $vehicleId);
        $unit = 'U-10002';
        $node = $nodeId;

        $delivery->recordPreparationScan($unit, $this->geo(), $this->device(), $this->at('08:00'), Uuid::generate()->value);
        $delivery->recordLoadScan($unit, $this->geo(), $this->device(), $this->at('08:20'), Uuid::generate()->value);
        $delivery->recordUnloadScan($node, $unit, $this->geo(), $this->device(), $this->at('10:00'), Uuid::generate()->value);
        $delivery->recordNodeReception($node, $unit, $this->geo(), $this->device(), $this->at('10:05'), Uuid::generate()->value);

        return $delivery;
    }

    /**
     * Intento de entrega fallido por cliente ausente: preparación → carga → fallo.
     */
    private function failedSample(string $shipmentId, string $vehicleId): Delivery
    {
        $delivery = Delivery::start($this->deliveries->nextIdentity(), $shipmentId, $vehicleId);
        $unit = 'U-10003';

        $delivery->recordPreparationScan($unit, $this->geo(), $this->device(), $this->at('08:10'), Uuid::generate()->value);
        $delivery->recordLoadScan($unit, $this->geo(), $this->device(), $this->at('08:25'), Uuid::generate()->value);
        $delivery->recordFailed(FailureReason::CustomerAbsent, $unit, $this->geo(), $this->device(), $this->at('12:40'), Uuid::generate()->value);

        return $delivery;
    }

    private function geo(): GeoPoint
    {
        // Centro de La Paz, con una pequeña variación para que los puntos no sean idénticos.
        return GeoPoint::of(-16.5 + (mt_rand(-50, 50) / 10000), -68.15 + (mt_rand(-50, 50) / 10000));
    }

    private function device(): DeviceContext
    {
        return DeviceContext::of('device-demo-01', 'user-demo-07');
    }

    private function at(string $hhmm): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-13T'.$hhmm.':00+00:00');
    }
}
