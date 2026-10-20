<?php

declare(strict_types=1);

use Logistics\Planning\Application\Command\PublishPlan\PublishPlan;
use Logistics\Planning\Application\Command\ReorderStops\ReorderStops;
use Logistics\Planning\Application\Service\PlanGeometryRefresher;
use Logistics\Planning\Database\Factories\PlannedStopRecordFactory;
use Logistics\Planning\Database\Factories\PlanRecordFactory;
use Logistics\Planning\Domain\Contract\RouteGeometryProvider;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\ValueObject\RouteGeometry;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlanRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\Factories\ShipmentRecordFactory;

// La geometría de la ruta planificada se calcula también en los planes armados a mano/por propuesta,
// reordenados o publicados sin ella (antes solo la tenía el auto-plan). El proveedor de geometría se
// falsea: devuelve la polilínea de los propios puntos y cuenta las llamadas.

/**
 * Proveedor falso: polilínea = los puntos recibidos ([lng, lat]); cuenta las llamadas y puede fallar.
 */
final class FakeRouteGeometryProvider implements RouteGeometryProvider
{
    public int $calls = 0;

    /** @var list<GeoPoint> */
    public array $lastPoints = [];

    public bool $throws = false;

    public function geometryFor(array $orderedPoints): ?RouteGeometry
    {
        $this->calls++;
        if ($this->throws) {
            throw new RuntimeException('ruteador caído');
        }
        $this->lastPoints = array_values($orderedPoints);
        if (count($orderedPoints) < 2) {
            return null;
        }

        return RouteGeometry::of(
            array_map(static fn (GeoPoint $p): array => [$p->longitude, $p->latitude], array_values($orderedPoints)),
            1000.0,
            120.0,
        );
    }
}

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
    [$published] = seedPlanWithGeocodedStops(PlanRecordFactory::new()->published()->onDate('2026-10-13'), 2);
    [$draft] = seedPlanWithGeocodedStops(PlanRecordFactory::new()->onDate('2026-10-13'), 2);

    $this->artisan('logistics:planning:backfill-geometry', ['--date' => '2026-10-13'])
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
    [$published] = seedPlanWithGeocodedStops(PlanRecordFactory::new()->published(), 2);
    [$draft] = seedPlanWithGeocodedStops(PlanRecordFactory::new(), 2);
    app(PlanGeometryRefresher::class)->backfill(null, false);

    $this->artisan('logistics:planning:backfill-geometry', ['--force' => true])
        ->expectsOutputToContain('con geometría nueva: 1')
        ->assertSuccessful();
});

it('rechaza una fecha inválida', function (): void {
    $this->artisan('logistics:planning:backfill-geometry', ['--date' => 'ayer'])->assertFailed();
});

it('ensureForVehicleAndDate persiste la geometría del plan del camión y día', function (): void {
    [$plan] = seedPlanWithGeocodedStops(PlanRecordFactory::new()->published()->onDate('2026-10-13'), 2);

    $refresher = app(PlanGeometryRefresher::class);

    expect($refresher->ensureForVehicleAndDate((string) $plan->vehicle_id, '2026-10-13'))->toBeTrue()
        ->and(storedCoordinates($plan))->toHaveCount(2)
        ->and($refresher->ensureForVehicleAndDate((string) $plan->vehicle_id, '2026-10-13'))->toBeFalse()
        ->and($refresher->ensureForVehicleAndDate('no-existe', '2026-10-13'))->toBeFalse();
});
