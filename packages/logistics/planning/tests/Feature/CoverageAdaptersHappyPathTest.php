<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Logistics\Fleet\Database\Factories\CrewFactory;
use Logistics\Fleet\Database\Factories\VehicleFactory;
use Logistics\Network\Database\Factories\NodeFactory;
use Logistics\Planning\Database\Factories\PlannedStopRecordFactory;
use Logistics\Planning\Database\Factories\PlanRecordFactory;
use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;
use Logistics\Planning\Domain\ValueObject\DistanceMatrix;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;
use Logistics\Planning\Domain\ValueObject\RouteComparisonProblem;
use Logistics\Planning\Domain\ValueObject\VehicleCapacitySpec;
use Logistics\Planning\Infrastructure\Adapter\EloquentPendingDeliveryProvider;
use Logistics\Planning\Infrastructure\Adapter\EloquentRouteComparisonProblemProvider;
use Logistics\Planning\Infrastructure\Adapter\FleetVehicleAvailabilityProvider;
use Logistics\Planning\Infrastructure\Routing\OsrmRoadMatrixProvider;
use Logistics\Planning\Infrastructure\Settings\ConfiguredAutoPlanSettings;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\Factories\ShipmentLineRecordFactory;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\Factories\ShipmentRecordFactory;

// Ejercita las RAMAS FELICES de los adaptadores de lectura de Planificación con datos cruzados sembrados
// (ShipmentRecord/VehicleModel/CrewModel/NetworkNode): recorre toCandidate, el armado de specs de flota y
// la reconstrucción del problema de comparación de backends. El localizador de departamento vive sobre
// PostGIS: sin polígonos sembrados devuelve null (ubicación desconocida), lo cual recorre igualmente las
// líneas del adaptador. OSRM se falsea para que el motor no dependa de la red.

beforeEach(function (): void {
    Http::fake(['*' => Http::response(null, 500)]);
});

// ──────────────────────────── EloquentPendingDeliveryProvider.toCandidate ────────────────────────────

it('arma candidatos pendientes desde envíos RECEIVED con líneas, ventana y requisitos', function (): void {
    $date = PlanDate::fromString((new DateTimeImmutable('today'))->format('Y-m-d'));

    // Envío recibido con coordenadas, ventana horaria (rama de slot) y una línea (rama withSum).
    $shipment = ShipmentRecordFactory::new()
        ->withDeliverySlot($date->toString(), '09:00', '12:00')
        ->create([
            'status' => 'RECEIVED',
            'lat' => -16.4955,
            'lng' => -68.1336,
        ]);
    ShipmentLineRecordFactory::new()->create([
        'shipment_id' => $shipment->getKey(),
        'quantity' => 3,
    ]);

    // Requisito de ruteo (R-04) del read-side propio: recorre el loader shipmentRequirements().
    DB::table('planning_shipment_requirements')->insert([
        'shipment_id' => (string) $shipment->getKey(),
        'load_type' => 'standard',
        'zone' => 'LP',
        'height_m' => 1.50,
        'weight_kg' => 120.0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $candidates = app(EloquentPendingDeliveryProvider::class)->pendingFor($date);

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0])->toBeInstanceOf(DeliveryCandidate::class)
        ->and($candidates[0]->shipmentId)->toBe((string) $shipment->getKey())
        // 3 bultos × 10 kg por bulto (default de config): recorre la estimación de carga.
        ->and($candidates[0]->weightKg)->toBe(30.0);
});

// ──────────────────────────── FleetVehicleAvailabilityProvider ────────────────────────────

it('arma specs de la flota disponible con nodo base, tripulación y restricciones', function (): void {
    $date = PlanDate::fromString((new DateTimeImmutable('today'))->format('Y-m-d'));

    $node = NodeFactory::new()->distributionCenter()->create([
        'lat' => -16.5000,
        'lng' => -68.1500,
    ]);
    $vehicle = VehicleFactory::new()->homeBase((string) $node->getKey())->create([
        'status' => 'available',
        'capacity_kg' => 5000,
        'capacity_m3' => 20.0,
    ]);
    // Tripulación armada para ese día y ese camión: recorre crewedVehicleIdsFor() y hasCrew = true.
    CrewFactory::new()->forVehicle((string) $vehicle->getKey())->assembledFor($date->toString())->create();

    // Restricciones (R-04) con columnas jsonb: recorre restrictionProfiles() + decodeJson().
    DB::table('planning_vehicle_restrictions')->insert([
        'vehicle_id' => (string) $vehicle->getKey(),
        'allowed_load_types' => json_encode(['standard']),
        'allowed_zones' => json_encode(['LP']),
        'forbidden_zones' => json_encode([]),
        'max_height_m' => 3.50,
        'max_weight_kg' => 8000.0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $specs = app(FleetVehicleAvailabilityProvider::class)->availableFor($date);

    expect($specs)->toHaveCount(1)
        ->and($specs[0])->toBeInstanceOf(VehicleCapacitySpec::class)
        ->and($specs[0]->vehicleId)->toBe((string) $vehicle->getKey())
        ->and($specs[0]->hasCrew)->toBeTrue();
});

// ──────────────────── EloquentRouteComparisonProblemProvider.candidatesFor/vehicleSpecFor ────────────────────

it('reconstruye el problema de comparación de un plan con envíos y camión reales', function (): void {
    $node = NodeFactory::new()->distributionCenter()->create([
        'lat' => -16.5000,
        'lng' => -68.1500,
    ]);
    $vehicle = VehicleFactory::new()->homeBase((string) $node->getKey())->create([
        'status' => 'available',
        'capacity_kg' => 5000,
        'capacity_m3' => 20.0,
    ]);

    // Dos envíos con coordenadas, referenciados por las paradas del plan.
    $shipmentA = ShipmentRecordFactory::new()->create(['lat' => -16.4955, 'lng' => -68.1336]);
    $shipmentB = ShipmentRecordFactory::new()->create(['lat' => -16.5089, 'lng' => -68.1193]);
    ShipmentLineRecordFactory::new()->create(['shipment_id' => $shipmentA->getKey(), 'quantity' => 2]);

    $plan = PlanRecordFactory::new()->proposed()->create([
        'vehicle_id' => (string) $vehicle->getKey(),
    ]);
    PlannedStopRecordFactory::new()->scheduled(1)->create([
        'plan_id' => $plan->getKey(),
        'shipment_id' => (string) $shipmentA->getKey(),
        'sequence' => 1,
    ]);
    PlannedStopRecordFactory::new()->scheduled(2)->create([
        'plan_id' => $plan->getKey(),
        'shipment_id' => (string) $shipmentB->getKey(),
        'sequence' => 2,
    ]);

    // Restricción del camión: recorre restrictionProfile() con fila presente.
    DB::table('planning_vehicle_restrictions')->insert([
        'vehicle_id' => (string) $vehicle->getKey(),
        'allowed_load_types' => json_encode(['standard']),
        'allowed_zones' => null,
        'forbidden_zones' => null,
        'max_height_m' => null,
        'max_weight_kg' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $problem = app(EloquentRouteComparisonProblemProvider::class)->forPlan(PlanId::fromString((string) $plan->getKey()));

    expect($problem)->toBeInstanceOf(RouteComparisonProblem::class)
        ->and($problem->candidates)->toHaveCount(2)
        ->and($problem->vehicle)->toBeInstanceOf(VehicleCapacitySpec::class)
        ->and($problem->vehicle->vehicleId)->toBe((string) $vehicle->getKey());
});

it('devuelve null cuando el camión del plan no existe en la flota', function (): void {
    // Plan con vehicle_id que no corresponde a ningún VehicleModel: vehicleSpecFor() degrada a null.
    $plan = PlanRecordFactory::new()->proposed()->withStops(2)->create([
        'vehicle_id' => Uuid::generate()->value,
    ]);

    expect(app(EloquentRouteComparisonProblemProvider::class)->forPlan(PlanId::fromString((string) $plan->getKey())))
        ->toBeNull();
});

// ──────────────────────────── OsrmRoadMatrixProvider (chunking + fake OSRM) ────────────────────────────

/**
 * Cuatro puntos sobre un meridiano para armar matrices 4×4.
 *
 * @return list<GeoPoint>
 */
function roadMatrixPoints(): array
{
    return [
        GeoPoint::of(-16.50, -68.15),
        GeoPoint::of(-16.51, -68.14),
        GeoPoint::of(-16.52, -68.13),
        GeoPoint::of(-16.53, -68.12),
    ];
}

/**
 * Construye el proveedor de matriz con caché de arreglo y el tope de coordenadas indicado.
 */
function makeRoadMatrixProvider(int $maxTableCoordinates): OsrmRoadMatrixProvider
{
    return new OsrmRoadMatrixProvider(
        app(Factory::class),
        Cache::store('array'),
        'http://osrm.test',
        2,
        $maxTableCoordinates,
        0, // sin TTL: no toca Valkey al escribir caché
        30.0,
        'driving',
    );
}

it('arma la matriz OSRM en un solo bloque cuando entra en una llamada', function (): void {
    Http::fake(['*' => Http::response([
        'code' => 'Ok',
        'distances' => [[0.0, 100.0, 200.0, 300.0], [100.0, 0.0, 100.0, 200.0], [200.0, 100.0, 0.0, 100.0], [300.0, 200.0, 100.0, 0.0]],
        'durations' => [[0.0, 60.0, 120.0, 180.0], [60.0, 0.0, 60.0, 120.0], [120.0, 60.0, 0.0, 60.0], [180.0, 120.0, 60.0, 0.0]],
    ], 200)]);

    $matrix = makeRoadMatrixProvider(10)->matrixFor(roadMatrixPoints());

    expect($matrix)->toBeInstanceOf(DistanceMatrix::class)
        ->and($matrix->size())->toBe(4)
        ->and($matrix->distanceMeters(0, 1))->toBeGreaterThan(0.0);
});

it('arma la matriz OSRM por BLOQUES cuando supera el tope por llamada', function (): void {
    // Respuesta Ok pero con celdas nulas: cada celda cae al relleno haversine (sin huecos), lo que
    // ejercita el camino de los bloques y el fallback por celda sin degradar toda la matriz.
    Http::fake(['*' => Http::response([
        'code' => 'Ok',
        'distances' => [[null]],
        'durations' => [[null]],
    ], 200)]);

    // Tope 2 con 4 puntos: bloques de 1 → múltiples submatrices (sameBlock y cruzados).
    $matrix = makeRoadMatrixProvider(2)->matrixFor(roadMatrixPoints());

    expect($matrix)->toBeInstanceOf(DistanceMatrix::class)
        ->and($matrix->size())->toBe(4)
        // Celda fuera de la respuesta mínima: haversine > 0 entre dos puntos distintos.
        ->and($matrix->distanceMeters(0, 3))->toBeGreaterThan(0.0);
});

it('degrada a haversine cuando OSRM responde con error', function (): void {
    // El fake global (500) ya fuerza el fallback: la matriz se arma por línea recta.
    $matrix = makeRoadMatrixProvider(10)->matrixFor(roadMatrixPoints());

    expect($matrix->size())->toBe(4)
        ->and($matrix->distanceMeters(0, 1))->toBeGreaterThan(0.0)
        ->and($matrix->distanceMeters(0, 0))->toBe(0.0);
});

it('devuelve matriz degenerada 1x1 para cero o un punto', function (): void {
    $provider = makeRoadMatrixProvider(10);

    expect($provider->matrixFor([])->size())->toBe(1)
        ->and($provider->matrixFor([GeoPoint::of(-16.5, -68.1)])->size())->toBe(1);
});

// ──────────────────────────── ConfiguredAutoPlanSettings ────────────────────────────

it('resuelve los parámetros del auto-planificador con y sin overrides', function (): void {
    $settings = new ConfiguredAutoPlanSettings(app('config'));
    $date = PlanDate::fromString((new DateTimeImmutable('today'))->format('Y-m-d'));

    // Sin overrides: usa los defaults de config (o los del propio provider).
    $defaults = $settings->parametersFor($date, PlanStrategy::Optimization, []);
    expect($defaults->strategy)->toBe(PlanStrategy::Optimization)
        ->and($defaults->departAt)->toContain($date->toString());

    // Con overrides: recorre todas las ramas de resolución (hora, holgura, pesos, origen).
    $origin = Uuid::generate()->value;
    $overridden = $settings->parametersFor($date, PlanStrategy::Slots, [
        'depart_at' => $date->toString().'T06:15:00',
        'capacity_slack' => 0.25,
        'default_weight_kg_per_package' => 12.5,
        'default_volume_m3_per_package' => 0.08,
        'origin_node_id' => $origin,
    ]);

    expect($overridden->strategy)->toBe(PlanStrategy::Slots)
        ->and($overridden->departAt)->toBe($date->toString().'T06:15:00')
        ->and($overridden->capacitySlackRatio)->toBe(0.25)
        ->and($overridden->originNodeId)->toBe($origin);
});
