<?php

declare(strict_types=1);

use Logistics\Planning\Application\Command\PublishPlan\PublishPlan;
use Logistics\Planning\Application\Command\ReorderStops\ReorderStops;
use Logistics\Planning\Application\Service\PlanGeometryRefresher;
use Logistics\Planning\Database\Factories\PlannedStopRecordFactory;
use Logistics\Planning\Database\Factories\PlanRecordFactory;
use Logistics\Planning\Domain\Contract\RouteGeometryProvider;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlanRecord;
use Logistics\Planning\Tests\Feature\FakeRouteGeometryProvider;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\Factories\ShipmentRecordFactory;

// Fecha fija de los planes sembrados en las pruebas de backfill de geometría.
const PLAN_DATE = '2026-10-13';

// La geometría de la ruta planificada se calcula también en los planes armados a mano/por propuesta,
// reordenados o publicados sin ella (antes solo la tenía el auto-plan). El proveedor de geometría se
// falsea: devuelve la polilínea de los propios puntos y cuenta las llamadas.

beforeEach(function (): void {
    config()->set('logistics-planning.auto_planner.origin_node_id', null);
    $this->geometryProvider = new FakeRouteGeometryProvider;
    app()->instance(RouteGeometryProvider::class, $this->geometryProvider);
});

/**
 * Siembra un plan con N paradas cuyos envíos tienen coordenadas; la tercera (si hay) sin geocodificar.
 *
 * @return array{0: PlanRecord, 1: list<string>}
 */
function seedPlanWithGeocodedStops(PlanRecordFactory $factory, int $count, bool $lastWithoutCoords = false): array
{
    $plan = $factory->create();
    $ids = [];
    for ($i = 1; $i <= $count; $i++) {
        $withCoords = !($lastWithoutCoords && $i === $count);
        $shipment = ShipmentRecordFactory::new()->create($withCoords
            ? ['lat' => -16.50 - $i / 100, 'lng' => -68.13 - $i / 100]
            : ['lat' => null, 'lng' => null]);
        $ids[] = (string) $shipment->getKey();
        PlannedStopRecordFactory::new()->create([
            'plan_id' => $plan->getKey(),
            'shipment_id' => $shipment->getKey(),
            'sequence' => $i,
        ]);
    }

    return [$plan, $ids];
}

/**
 * @return list<array{0: float, 1: float}>|null
 */
function storedCoordinates(PlanRecord $plan): ?array
{
    $raw = PlanRecord::query()->whereKey($plan->getKey())->toBase()->value('route_geometry');
    $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

    return is_array($decoded) ? $decoded['coordinates'] : null;
}

it('PublishPlan calcula la geometría si el plan no la tiene', function (): void {
    [$plan] = seedPlanWithGeocodedStops(PlanRecordFactory::new()->proposed(), 3);

    app(CommandBus::class)->dispatch(new PublishPlan((string) $plan->getKey()));

    expect(PlanRecord::query()->whereKey($plan->getKey())->value('status'))->toBe('PUBLISHED')
        ->and(storedCoordinates($plan))->toHaveCount(3)
        ->and($this->geometryProvider->calls)->toBe(1);
});

it('PublishPlan publica igual si el proveedor de geometría falla', function (): void {
    $this->geometryProvider->throws = true;
    [$plan] = seedPlanWithGeocodedStops(PlanRecordFactory::new()->proposed(), 2);

    app(CommandBus::class)->dispatch(new PublishPlan((string) $plan->getKey()));

    expect(PlanRecord::query()->whereKey($plan->getKey())->value('status'))->toBe('PUBLISHED')
        ->and(storedCoordinates($plan))->toBeNull();
});

it('ReorderStops recalcula la geometría con el nuevo orden', function (): void {
    [$plan, $ids] = seedPlanWithGeocodedStops(PlanRecordFactory::new(), 3);
    $reversed = array_reverse($ids);

    app(CommandBus::class)->dispatch(new ReorderStops((string) $plan->getKey(), $reversed));

    $coords = storedCoordinates($plan);
    // La primera coordenada es la del envío que quedó primero (el último sembrado: i=3).
    expect($coords)->toHaveCount(3)
        ->and($coords[0][1])->toEqualWithDelta(-16.53, 0.0001);
});

it('omite las paradas sin geocodificar al armar los puntos', function (): void {
    [$plan] = seedPlanWithGeocodedStops(PlanRecordFactory::new(), 3, lastWithoutCoords: true);

    $result = app(PlanGeometryRefresher::class)->backfill(null, false);

    expect($result['updated'])->toBe(1)
        ->and(storedCoordinates($plan))->toHaveCount(2);
});

it('el backfill completa planes publicados sin geometría y es idempotente', function (): void {
    [$published] = seedPlanWithGeocodedStops(PlanRecordFactory::new()->published()->onDate(PLAN_DATE), 2);
    [$draft] = seedPlanWithGeocodedStops(PlanRecordFactory::new()->onDate(PLAN_DATE), 2);

    $this->artisan('logistics:planning:backfill-geometry', ['--date' => PLAN_DATE])
        ->expectsOutputToContain('con geometría nueva: 2')
        ->assertSuccessful();

    expect(storedCoordinates($published))->toHaveCount(2)
        ->and(storedCoordinates($draft))->toHaveCount(2)
        ->and(PlanRecord::query()->whereKey($published->getKey())->value('status'))->toBe('PUBLISHED');

    // Segunda corrida: no queda nada sin geometría.
    $calls = $this->geometryProvider->calls;
    $this->artisan('logistics:planning:backfill-geometry')
        ->expectsOutputToContain('con geometría nueva: 0')
        ->assertSuccessful();
    expect($this->geometryProvider->calls)->toBe($calls);
});

it('el backfill con --force recalcula los no publicados y respeta los publicados', function (): void {
    seedPlanWithGeocodedStops(PlanRecordFactory::new()->published(), 2);
    seedPlanWithGeocodedStops(PlanRecordFactory::new(), 2);
    app(PlanGeometryRefresher::class)->backfill(null, false);

    $this->artisan('logistics:planning:backfill-geometry', ['--force' => true])
        ->expectsOutputToContain('con geometría nueva: 1')
        ->assertSuccessful();
});

it('recalcula un plan PUBLICADO con geometría DEGENERADA (puntos iguales) sin --force', function (): void {
    [$plan] = seedPlanWithGeocodedStops(PlanRecordFactory::new()->published(), 2);
    // Geometría basura persistida: dos coordenadas IGUALES (colapsada a un punto), como la que vimos en staging.
    PlanRecord::query()->whereKey($plan->getKey())->toBase()->update([
        'route_geometry' => json_encode([
            'type' => 'LineString',
            'coordinates' => [[-63.169888, -17.756876], [-63.169888, -17.756876]],
            'distance_meters' => 0,
            'duration_seconds' => 0,
        ], JSON_THROW_ON_ERROR),
    ]);

    // Sin --force: una geometría degenerada se trata como «falta geometría», así que se recalcula aunque el
    // plan esté PUBLICADO (una hoja de ruta buena sí quedaría congelada).
    $result = app(PlanGeometryRefresher::class)->backfill(null, false);

    $coords = storedCoordinates($plan);
    expect($result['updated'])->toBe(1)
        ->and($coords)->toHaveCount(2)
        // Dos puntos DISTINTOS = dibujable: ya no es la geometría colapsada.
        ->and($coords[0])->not->toBe($coords[1])
        ->and(PlanRecord::query()->whereKey($plan->getKey())->value('status'))->toBe('PUBLISHED');
});

it('NO reescribe un plan publicado con geometría VÁLIDA (hoja congelada)', function (): void {
    [$plan] = seedPlanWithGeocodedStops(PlanRecordFactory::new()->published(), 2);
    // Primera corrida: le calcula una geometría válida (dibujable).
    app(PlanGeometryRefresher::class)->backfill(null, false);
    $before = storedCoordinates($plan);

    // Segunda corrida incluso con --force: no la toca (ya es válida y está publicada).
    $calls = $this->geometryProvider->calls;
    $result = app(PlanGeometryRefresher::class)->backfill(null, true);

    expect($result['updated'])->toBe(0)
        ->and(storedCoordinates($plan))->toBe($before)
        ->and($this->geometryProvider->calls)->toBe($calls);
});

it('rechaza una fecha inválida', function (): void {
    $this->artisan('logistics:planning:backfill-geometry', ['--date' => 'ayer'])->assertFailed();
});

it('ensureForVehicleAndDate persiste la geometría del plan del camión y día', function (): void {
    [$plan] = seedPlanWithGeocodedStops(PlanRecordFactory::new()->published()->onDate(PLAN_DATE), 2);

    $refresher = app(PlanGeometryRefresher::class);

    expect($refresher->ensureForVehicleAndDate((string) $plan->vehicle_id, PLAN_DATE))->toBeTrue()
        ->and(storedCoordinates($plan))->toHaveCount(2)
        ->and($refresher->ensureForVehicleAndDate((string) $plan->vehicle_id, PLAN_DATE))->toBeFalse()
        ->and($refresher->ensureForVehicleAndDate('no-existe', PLAN_DATE))->toBeFalse();
});
