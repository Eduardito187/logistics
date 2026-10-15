<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Logistics\Planning\Application\Query\CompareRoutingBackends\CompareRoutingBackends;
use Logistics\Planning\Application\Query\CompareRoutingBackends\CompareRoutingBackendsHandler;
use Logistics\Planning\Application\ReadModel\RoutingBackendComparisonView;
use Logistics\Planning\Domain\Contract\AutoPlanSettingsProvider;
use Logistics\Planning\Domain\Contract\RouteComparisonProblemProvider;
use Logistics\Planning\Domain\Contract\RoutingBackend;
use Logistics\Planning\Domain\Service\ClarkeWrightSolver;
use Logistics\Planning\Domain\Service\DailyPlanComposer;
use Logistics\Planning\Domain\Service\DepartmentRouteGrouping;
use Logistics\Planning\Domain\Service\RouteSequencer;
use Logistics\Planning\Domain\Service\VehicleCompatibility;
use Logistics\Planning\Domain\ValueObject\AutoPlanParameters;
use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;
use Logistics\Planning\Domain\ValueObject\RouteComparisonProblem;
use Logistics\Planning\Domain\ValueObject\RoutingResult;
use Logistics\Planning\Domain\ValueObject\VehicleCapacitySpec;
use Logistics\Planning\Infrastructure\Routing\BuiltinRoutingBackend;
use Logistics\Planning\Infrastructure\Routing\OsrmRoadMatrixProvider;
use Logistics\Planning\Infrastructure\Routing\OsrmRouteGeometryProvider;
use Logistics\Planning\Infrastructure\Routing\OsrmRouteOptimizer;
use Logistics\Planning\Infrastructure\Routing\OsrmTripRoutingBackend;
use Logistics\Planning\Infrastructure\Routing\VroomRoutingBackend;

// Ejercita los backends de ruteo y los proveedores OSRM/VROOM (Infrastructure) con Http::fake, sin red
// real: se arman los adaptadores con el cliente HTTP y la caché del contenedor y se recorren tanto la
// respuesta válida como la DEGRADACIÓN (servidor caído / respuesta no usable → haversine / línea recta /
// motor interno). Copia el patrón de Http::fake/fallback de OsrmRouteOptimizerTest.php (que ya pasa).

/**
 * Parámetros de corrida de prueba: sin holgura para que las cuentas sean exactas y deterministas.
 */
function rbParams(?PlanStrategy $strategy = null): AutoPlanParameters
{
    return new AutoPlanParameters(
        $strategy ?? PlanStrategy::Optimization,
        '2026-10-15T07:30:00+00:00',
        10.0,
        0.05,
        0.0,
    );
}

/**
 * Camión disponible con nodo base resuelto (sirve de depósito aproximado del grupo).
 */
function rbVehicle(
    string $id,
    ?string $departmentId = null,
    bool $hasCrew = true,
    float $kg = 2000.0,
    float $m3 = 2000.0,
): VehicleCapacitySpec {
    return VehicleCapacitySpec::of($id, $kg, $m3, 'node-base', $hasCrew, $departmentId, -16.5, -68.15);
}

/**
 * Pedido candidato a entregar en un departamento, con coordenadas ligeramente dispersas.
 */
function rbCandidate(string $id, string $departmentId, float $lat = -16.51, float $lng = -68.16): DeliveryCandidate
{
    return DeliveryCandidate::of($id, $lat, $lng, 8.0, 0.1, $departmentId, $departmentId);
}

/**
 * Cliente HTTP del contenedor: el MISMO singleton que falsea Http::fake(), para que los fakes apliquen.
 */
function rbHttp(): Factory
{
    return app(Factory::class);
}

/**
 * Proveedor de geometría OSRM apuntando a un host de prueba.
 */
function rbGeometry(): OsrmRouteGeometryProvider
{
    return new OsrmRouteGeometryProvider(rbHttp(), 'http://osrm.test', 2, 30.0);
}

/**
 * Backend builtin (Clarke-Wright + 2-opt) armado con matriz/geometría OSRM de prueba y servicios de dominio.
 */
function rbBuiltin(): BuiltinRoutingBackend
{
    $cache = app(Repository::class);
    $matrix = new OsrmRoadMatrixProvider(rbHttp(), $cache, 'http://osrm.test', 100, 100, 0, 30.0);

    return new BuiltinRoutingBackend(
        $matrix,
        rbGeometry(),
        new ClarkeWrightSolver,
        new RouteSequencer,
        new DepartmentRouteGrouping,
        new VehicleCompatibility,
    );
}

it('builtin asigna paradas a un camión degradando la matriz/geometría a haversine', function (): void {
    // OSRM caído: la matriz cae a haversine y la geometría a línea recta; el VRP igual arma la ruta.
    Http::fake(['*' => Http::response(null, 500)]);

    $result = rbBuiltin()->route(
        new DateTimeImmutable('2026-10-15T07:30:00+00:00'),
        GeoPoint::of(-16.5, -68.15),
        [rbCandidate('shp-a', 'LP', -16.51, -68.16), rbCandidate('shp-b', 'LP', -16.52, -68.14)],
        [rbVehicle('veh-1', 'LP')],
        rbParams(),
    );

    expect($result)->toBeInstanceOf(RoutingResult::class)
        ->and($result->trucksPlanned())->toBe(1)
        ->and($result->stopsPlanned())->toBe(2)
        ->and($result->unassignedCount())->toBe(0);
});

it('builtin deja sin asignar cuando ningún camión del departamento tiene tripulación', function (): void {
    Http::fake(['*' => Http::response(null, 500)]);

    $result = rbBuiltin()->route(
        new DateTimeImmutable('2026-10-15T07:30:00+00:00'),
        null,
        [rbCandidate('shp-a', 'LP')],
        [rbVehicle('veh-1', 'LP', hasCrew: false)],
        rbParams(),
    );

    expect($result->trucksPlanned())->toBe(0)
        ->and($result->unassignedCount())->toBe(1);
});

it('vroom delega al builtin cuando no hay VROOM_URL configurado', function (): void {
    Http::fake(['*' => Http::response(null, 500)]);

    $vroom = new VroomRoutingBackend(rbHttp(), rbGeometry(), new DepartmentRouteGrouping, rbBuiltin(), null, 2);

    $result = $vroom->route(
        new DateTimeImmutable('2026-10-15T07:30:00+00:00'),
        GeoPoint::of(-16.5, -68.15),
        [rbCandidate('shp-a', 'LP'), rbCandidate('shp-b', 'LP', -16.52, -68.14)],
        [rbVehicle('veh-1', 'LP')],
        rbParams(),
    );

    expect($result->trucksPlanned())->toBe(1)
        ->and($result->stopsPlanned())->toBe(2);
});

it('vroom resuelve el grupo con la solución del servidor VROOM', function (): void {
    // El servidor VROOM devuelve una solución (code 0) con una ruta de dos jobs; la geometría degrada.
    Http::fake([
        '*vroom*' => Http::response([
            'code' => 0,
            'routes' => [[
                'vehicle' => 1,
                'steps' => [
                    ['type' => 'job', 'id' => 1, 'arrival' => 600],
                    ['type' => 'job', 'id' => 2, 'arrival' => 1200],
                ],
            ]],
        ], 200),
        '*' => Http::response(null, 500),
    ]);

    $vroom = new VroomRoutingBackend(rbHttp(), rbGeometry(), new DepartmentRouteGrouping, rbBuiltin(), 'http://vroom.local', 2);

    $result = $vroom->route(
        new DateTimeImmutable('2026-10-15T07:30:00+00:00'),
        GeoPoint::of(-16.5, -68.15),
        [rbCandidate('shp-a', 'LP'), rbCandidate('shp-b', 'LP', -16.52, -68.14)],
        [rbVehicle('veh-1', 'LP')],
        rbParams(),
    );

    expect($result->trucksPlanned())->toBe(1)
        ->and($result->stopsPlanned())->toBe(2)
        ->and($result->unassignedCount())->toBe(0);
});

it('vroom cae al builtin cuando el servidor VROOM responde con error', function (): void {
    // VROOM responde 500: cualquier fallo degrada al motor interno, nunca tumba la planificación.
    Http::fake([
        '*vroom*' => Http::response(null, 500),
        '*' => Http::response(null, 500),
    ]);

    $vroom = new VroomRoutingBackend(rbHttp(), rbGeometry(), new DepartmentRouteGrouping, rbBuiltin(), 'http://vroom.local', 2);

    $result = $vroom->route(
        new DateTimeImmutable('2026-10-15T07:30:00+00:00'),
        GeoPoint::of(-16.5, -68.15),
        [rbCandidate('shp-a', 'LP'), rbCandidate('shp-b', 'LP', -16.52, -68.14)],
        [rbVehicle('veh-1', 'LP')],
        rbParams(),
    );

    expect($result->trucksPlanned())->toBe(1)
        ->and($result->stopsPlanned())->toBe(2);
});

it('geometry OSRM arma la polilínea por calles cuando el servicio /route responde Ok', function (): void {
    Http::fake(['*' => Http::response([
        'code' => 'Ok',
        'routes' => [[
            'geometry' => ['coordinates' => [[-68.15, -16.5], [-68.16, -16.51], [-68.14, -16.52]]],
            'distance' => 3200.0,
            'duration' => 480.0,
        ]],
    ], 200)]);

    $geometry = rbGeometry()->geometryFor([
        GeoPoint::of(-16.5, -68.15),
        GeoPoint::of(-16.51, -68.16),
        GeoPoint::of(-16.52, -68.14),
    ]);

    expect($geometry)->not->toBeNull()
        ->and($geometry->distanceMeters)->toBe(3200.0)
        ->and($geometry->durationSeconds)->toBe(480.0);
});

it('geometry OSRM degrada a línea recta cuando el servicio no responde', function (): void {
    Http::fake(['*' => Http::response(null, 500)]);

    $geometry = rbGeometry()->geometryFor([
        GeoPoint::of(-16.5, -68.15),
        GeoPoint::of(-16.51, -68.16),
    ]);

    expect($geometry)->not->toBeNull()
        ->and($geometry->distanceMeters)->toBeGreaterThan(0.0);
});

it('geometry OSRM devuelve null con menos de dos puntos', function (): void {
    expect(rbGeometry()->geometryFor([GeoPoint::of(-16.5, -68.15)]))->toBeNull();
});

it('osrm_trip rutea por empaque greedy + /trip degradando al vecino más cercano', function (): void {
    Http::fake(['*' => Http::response(null, 500)]);

    $backend = new OsrmTripRoutingBackend(
        new DailyPlanComposer,
        new OsrmRouteOptimizer('http://osrm.test', 2),
        rbGeometry(),
    );

    $result = $backend->route(
        new DateTimeImmutable('2026-10-15T07:30:00+00:00'),
        null,
        [
            rbCandidate('shp-a', 'LP', -16.51, -68.16),
            rbCandidate('shp-b', 'LP', -16.52, -68.14),
            rbCandidate('shp-c', 'LP', -16.53, -68.17),
        ],
        [rbVehicle('veh-1', 'LP')],
        rbParams(),
    );

    expect($result->trucksPlanned())->toBe(1)
        ->and($result->stopsPlanned())->toBe(3);
});

it('la matriz OSRM /table arma distancias/tiempos y cachea la forma primitiva', function (): void {
    Http::fake(['*' => Http::response([
        'code' => 'Ok',
        'distances' => [[0.0, 100.0, 200.0], [100.0, 0.0, 150.0], [200.0, 150.0, 0.0]],
        'durations' => [[0.0, 60.0, 120.0], [60.0, 0.0, 90.0], [120.0, 90.0, 0.0]],
    ], 200)]);

    $cache = app(Repository::class);
    $provider = new OsrmRoadMatrixProvider(rbHttp(), $cache, 'http://osrm.test', 100, 100, 600, 30.0);

    $points = [GeoPoint::of(-16.5, -68.15), GeoPoint::of(-16.51, -68.16), GeoPoint::of(-16.52, -68.14)];

    $matrix = $provider->matrixFor($points);
    expect($matrix->distances[0][1])->toBe(100.0)
        ->and($matrix->durations[1][2])->toBe(90.0);

    // Segunda llamada con los mismos puntos: sale de la caché (misma forma).
    $cached = $provider->matrixFor($points);
    expect($cached->distances[0][2])->toBe(200.0);
});

it('la matriz OSRM degenera a 1x1 sin puntos y degrada a haversine en error', function (): void {
    Http::fake(['*' => Http::response(null, 500)]);
    $cache = app(Repository::class);
    $provider = new OsrmRoadMatrixProvider(rbHttp(), $cache, 'http://osrm.test', 100, 100, 0, 30.0);

    expect($provider->matrixFor([])->size())->toBe(1)
        ->and($provider->matrixFor([GeoPoint::of(-16.5, -68.15)])->size())->toBe(1);

    $matrix = $provider->matrixFor([GeoPoint::of(-16.5, -68.15), GeoPoint::of(-16.52, -68.14)]);
    expect($matrix->distanceMeters(0, 1))->toBeGreaterThan(0.0);
});

it('DepartmentRouteGrouping agrupa, filtra elegibles y resuelve el depósito', function (): void {
    $grouping = new DepartmentRouteGrouping;

    $groups = $grouping->groupCandidates(
        [rbCandidate('shp-a', 'LP'), rbCandidate('shp-b', 'SC'), rbCandidate('shp-c', 'LP')],
        PlanStrategy::Optimization,
    );
    expect($groups)->toHaveKey('LP')
        ->and($groups)->toHaveKey('SC')
        ->and($groups['LP'])->toHaveCount(2);

    $eligible = $grouping->eligibleVehicles([rbVehicle('v1', 'LP'), rbVehicle('v2', 'SC'), rbVehicle('v3', null)], 'LP');
    expect($eligible)->toHaveCount(2); // el de LP más el flexible

    // Sin origen y sin nodo base resuelto, el depósito es el medoide del grupo.
    $flexible = VehicleCapacitySpec::of('v4', 1000.0, 1000.0, 'node-base', true, null);
    $depot = $grouping->resolveDepot(null, [$flexible], [rbCandidate('shp-a', 'LP', -16.5, -68.15)]);
    expect($depot)->toBeInstanceOf(GeoPoint::class);

    $points = $grouping->pointsFor(GeoPoint::of(-16.5, -68.15), [rbCandidate('shp-a', 'LP')]);
    expect($points)->toHaveCount(2);
});

it('CompareRoutingBackends corre cada backend sobre el mismo plan y reporta disponibilidad', function (): void {
    // OSRM caído: los backends degradan (siguen disponibles con geometría recta); uno lanza y va a "no disponible".
    Http::fake(['*' => Http::response(null, 500)]);

    $problem = new RouteComparisonProblem(
        PlanDate::fromString('2026-10-15'),
        GeoPoint::of(-16.5, -68.15),
        [rbCandidate('shp-a', 'LP', -16.51, -68.16), rbCandidate('shp-b', 'LP', -16.52, -68.14)],
        rbVehicle('veh-1', null),
    );

    $provider = new class($problem) implements RouteComparisonProblemProvider
    {
        public function __construct(private ?RouteComparisonProblem $problem) {}

        public function forPlan(PlanId $planId): ?RouteComparisonProblem
        {
            return $this->problem;
        }
    };

    $settings = new class implements AutoPlanSettingsProvider
    {
        public function parametersFor(PlanDate $date, PlanStrategy $strategy, array $overrides): AutoPlanParameters
        {
            return rbParams();
        }
    };

    // Un backend que siempre lanza, para cubrir la rama "no disponible" de la comparación.
    $boom = new class implements RoutingBackend
    {
        public function route(
            DateTimeImmutable $departAt,
            ?GeoPoint $origin,
            array $candidates,
            array $vehicles,
            AutoPlanParameters $params,
        ): RoutingResult {
            throw new RuntimeException('backend caído');
        }
    };

    $handler = new CompareRoutingBackendsHandler(
        $provider,
        $settings,
        ['builtin' => rbBuiltin(), 'boom' => $boom],
        350,
        'BOB',
    );

    /** @var RoutingBackendComparisonView $view */
    $view = $handler->handle(new CompareRoutingBackends((string) Str::uuid7()));

    expect($view)->toBeInstanceOf(RoutingBackendComparisonView::class)
        ->and($view->stopCount)->toBe(2)
        ->and($view->backends)->toHaveCount(2);

    $byName = [];
    foreach ($view->backends as $stat) {
        $byName[$stat->backend] = $stat->available;
    }
    expect($byName['boom'])->toBeFalse()
        ->and($view->toArray())->toHaveKey('best_backend');
});

it('CompareRoutingBackends devuelve null cuando no se pudo reconstruir el problema', function (): void {
    $provider = new class implements RouteComparisonProblemProvider
    {
        public function forPlan(PlanId $planId): ?RouteComparisonProblem
        {
            return null;
        }
    };

    $settings = new class implements AutoPlanSettingsProvider
    {
        public function parametersFor(PlanDate $date, PlanStrategy $strategy, array $overrides): AutoPlanParameters
        {
            return rbParams();
        }
    };

    $handler = new CompareRoutingBackendsHandler($provider, $settings, [], 350, 'BOB');

    expect($handler->handle(new CompareRoutingBackends((string) Str::uuid7())))->toBeNull();
});
