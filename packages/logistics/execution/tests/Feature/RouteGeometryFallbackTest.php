<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Execution\Application\Command\AssembleDailyRoute\AssembleDailyRoute;
use Logistics\Execution\Application\Command\AssembleDailyRoute\RouteStopInput;
use Logistics\Execution\Application\Contract\RouteGeometryReader;
use Logistics\Planning\Domain\Contract\RouteGeometryProvider;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\ValueObject\RouteGeometry;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\Factories\ShipmentRecordFactory;

// La línea planificada SIEMPRE existe si la ruta tiene al menos dos puntos: geometría persistida del plan
// → (plan sin geometría) Planificación la calcula y persiste al vuelo → recta entre paradas de la ruta.

beforeEach(function (): void {
    $this->withoutMiddleware();
    config()->set('logistics-planning.auto_planner.origin_node_id', null);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Arma la ruta del chofer con dos paradas geocodificadas y devuelve [vehículo, envíos].
 *
 * @return array{0: string, 1: list<string>}
 */
function assembleTwoStopRoute(string $date): array
{
    $vehicle = Uuid::generate()->value;
    $a = (string) ShipmentRecordFactory::new()->create(['lat' => -16.50, 'lng' => -68.13])->getKey();
    $b = (string) ShipmentRecordFactory::new()->create(['lat' => -16.52, 'lng' => -68.15])->getKey();

    app(CommandBus::class)->dispatch(new AssembleDailyRoute(
        vehicleId: $vehicle,
        routeDate: $date,
        stops: [
            new RouteStopInput(shipmentId: $a, sequence: 1, lat: -16.50, lng: -68.13),
            new RouteStopInput(shipmentId: $b, sequence: 2, lat: -16.52, lng: -68.15),
        ],
    ));

    return [$vehicle, [$a, $b]];
}

it('sin plan devuelve una línea recta entre las paradas de la ruta', function (): void {
    [$vehicle] = assembleTwoStopRoute('2026-10-16');

    $response = $this->getJson("/api/v1/app/vehicles/{$vehicle}/route?date=2026-10-16")->assertOk();

    $response->assertJsonPath('data.route_geometry.type', 'LineString');
    $response->assertJsonPath('data.route_geometry.coordinates.0', [-68.13, -16.5]);
    $response->assertJsonPath('data.route_geometry.coordinates.1', [-68.15, -16.52]);
    expect($response->json('data.route_geometry.distance_meters'))->toBeGreaterThan(2000)
        ->and($response->json('data.route_geometry.duration_seconds'))->toBeGreaterThan(0);
});

it('con plan sin geometría la calcula vía planificación, la persiste y la sirve', function (): void {
    app()->instance(RouteGeometryProvider::class, new class implements RouteGeometryProvider
    {
        public function geometryFor(array $orderedPoints): ?RouteGeometry
        {
            // Simula una geometría por calles: más puntos que paradas.
            $coords = [];
            foreach ($orderedPoints as $p) {
                /** @var GeoPoint $p */
                $coords[] = [$p->longitude, $p->latitude];
                $coords[] = [$p->longitude + 0.001, $p->latitude];
            }

            return RouteGeometry::of($coords, 5000.0, 600.0);
        }
    });

    [$vehicle, $shipments] = assembleTwoStopRoute('2026-10-16');
    $planId = Uuid::generate()->value;
    DB::table('planning_plans')->insert([
        'id' => $planId,
        'plan_date' => '2026-10-16',
        'vehicle_id' => $vehicle,
        'status' => 'PUBLISHED',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    foreach ($shipments as $i => $shipmentId) {
        DB::table('planning_plan_stops')->insert([
            'id' => Uuid::generate()->value,
            'plan_id' => $planId,
            'shipment_id' => $shipmentId,
            'sequence' => $i + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $geometry = app(RouteGeometryReader::class)->forVehicleAndDate($vehicle, '2026-10-16');

    expect($geometry)->not->toBeNull()
        ->and($geometry['coordinates'])->toHaveCount(4)
        ->and($geometry['distance_meters'])->toEqual(5000.0)
        ->and(DB::table('planning_plans')->where('id', $planId)->value('route_geometry'))->not->toBeNull();
});

it('con una sola parada y sin plan no inventa geometría', function (): void {
    $vehicle = Uuid::generate()->value;
    app(CommandBus::class)->dispatch(new AssembleDailyRoute(
        vehicleId: $vehicle,
        routeDate: '2026-10-16',
        stops: [new RouteStopInput(shipmentId: Uuid::generate()->value, sequence: 1, lat: -16.5, lng: -68.1)],
    ));

    expect(app(RouteGeometryReader::class)->forVehicleAndDate($vehicle, '2026-10-16'))->toBeNull();
});
