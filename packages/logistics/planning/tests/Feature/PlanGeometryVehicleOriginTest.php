<?php

declare(strict_types=1);

use Logistics\Fleet\Database\Factories\VehicleFactory;
use Logistics\Network\Database\Factories\NodeFactory;
use Logistics\Planning\Application\Service\PlanGeometryRefresher;
use Logistics\Planning\Database\Factories\PlannedStopRecordFactory;
use Logistics\Planning\Database\Factories\PlanRecordFactory;
use Logistics\Planning\Domain\Contract\RouteGeometryProvider;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlanRecord;
use Logistics\Planning\Tests\Feature\FakeRouteGeometryProvider;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\Factories\ShipmentRecordFactory;

// La geometría del plan arranca en el PUNTO DE SALIDA del vehículo (su sede/almacén base; para una
// transportadora, su carrier_hub), PREFIRIÉNDOLO al depósito global. Esto unifica la asimetría: tanto la vía
// del plan como el respaldo de Ejecución anteponen el mismo origen por vehículo.

const ORIGIN_PLAN_DATE = '2026-10-13';

beforeEach(function (): void {
    // Sin depósito global por defecto: así el origen que aparezca es, inequívocamente, la sede del vehículo.
    config()->set('logistics-planning.auto_planner.origin_node_id', null);
    $this->geometryProvider = new FakeRouteGeometryProvider;
    app()->instance(RouteGeometryProvider::class, $this->geometryProvider);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Siembra un plan publicado del vehículo dado, en ORIGIN_PLAN_DATE, con dos paradas geocodificadas.
 */
function seedPublishedPlanForVehicle(string $vehicleId): PlanRecord
{
    $plan = PlanRecordFactory::new()->published()->onDate(ORIGIN_PLAN_DATE)->create(['vehicle_id' => $vehicleId]);
    for ($i = 1; $i <= 2; $i++) {
        $shipment = ShipmentRecordFactory::new()->create(['lat' => -16.50 - $i / 100, 'lng' => -68.13 - $i / 100]);
        PlannedStopRecordFactory::new()->create([
            'plan_id' => $plan->getKey(),
            'shipment_id' => $shipment->getKey(),
            'sequence' => $i,
        ]);
    }

    return $plan;
}

/**
 * @return list<array{0: float, 1: float}>|null
 */
function storedGeometryCoordinates(PlanRecord $plan): ?array
{
    $raw = PlanRecord::query()->whereKey($plan->getKey())->toBase()->value('route_geometry');
    $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

    return is_array($decoded) ? $decoded['coordinates'] : null;
}

it('la geometría del plan ARRANCA en la sede del vehículo (prefiere la sede al depósito global)', function (): void {
    // Nodo sede del camión (carrier_hub) con coordenadas conocidas: [lng, lat] = [-68.10, -16.40].
    $node = NodeFactory::new()->carrierHub()->create(['lat' => -16.40, 'lng' => -68.10]);
    $vehicle = (string) VehicleFactory::new()->homeBase((string) $node->getKey())->create()->getKey();

    $plan = seedPublishedPlanForVehicle($vehicle);

    expect(app(PlanGeometryRefresher::class)->ensureForVehicleAndDate($vehicle, ORIGIN_PLAN_DATE))->toBeTrue();

    // Sede + dos paradas = tres puntos; el PRIMERO es la sede del vehículo.
    $coords = storedGeometryCoordinates($plan);
    expect($coords)->toHaveCount(3)
        ->and($coords[0])->toBe([-68.10, -16.40]);
});

it('sin sede resoluble, cae al depósito GLOBAL configurado (no rompe el comportamiento previo)', function (): void {
    // Depósito global configurado y SIN fila de flota para el vehículo: arranca en el depósito global.
    $depot = NodeFactory::new()->distributionCenter()->create(['lat' => -16.30, 'lng' => -68.05]);
    config()->set('logistics-planning.auto_planner.origin_node_id', (string) $depot->getKey());

    $vehicle = Uuid::generate()->value;
    $plan = seedPublishedPlanForVehicle($vehicle);

    expect(app(PlanGeometryRefresher::class)->ensureForVehicleAndDate($vehicle, ORIGIN_PLAN_DATE))->toBeTrue();

    // Depósito global + dos paradas = tres puntos; el PRIMERO es el depósito global.
    $coords = storedGeometryCoordinates($plan);
    expect($coords)->toHaveCount(3)
        ->and($coords[0])->toBe([-68.05, -16.30]);
});

it('sin sede ni depósito global, la línea arranca en la primera parada', function (): void {
    $vehicle = Uuid::generate()->value;
    $plan = seedPublishedPlanForVehicle($vehicle);

    expect(app(PlanGeometryRefresher::class)->ensureForVehicleAndDate($vehicle, ORIGIN_PLAN_DATE))->toBeTrue();

    // Solo las dos paradas: la primera coordenada es la del primer envío.
    $coords = storedGeometryCoordinates($plan);
    expect($coords)->toHaveCount(2)
        ->and($coords[0])->toBe([-68.14, -16.51]);
});
