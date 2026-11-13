<?php

declare(strict_types=1);

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Planning\Application\Service\RouteReoptimizer;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Contract\RoadMatrixProvider;
use Logistics\Planning\Domain\Contract\RouteGeometryProvider;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\Model\DeliveryPlan;
use Logistics\Planning\Domain\Service\RouteSequencer;
use Logistics\Planning\Domain\ValueObject\DistanceMatrix;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Planning\Domain\ValueObject\PlannedStopId;
use Logistics\Planning\Domain\ValueObject\RouteGeometry;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Repositorio en memoria: guarda el último plan salvado para inspeccionarlo.
 */
function reoptPlanRepository(): PlanRepository
{
    return new class implements PlanRepository
    {
        public ?DeliveryPlan $saved = null;

        public function nextIdentity(): PlanId
        {
            return PlanId::generate();
        }

        public function save(DeliveryPlan $plan): void
        {
            $this->saved = $plan;
        }

        public function findById(PlanId $id): ?DeliveryPlan
        {
            return null;
        }

        public function findByDate(PlanDate $date): array
        {
            return [];
        }

        public function idsForGeometryBackfill(?PlanDate $date, bool $all): array
        {
            return [];
        }
    };
}

/**
 * Matriz 1D por longitud: la distancia/tiempo entre dos puntos es la diferencia absoluta de longitudes,
 * escalada a segundos. El camino abierto óptimo desde el depósito es el orden ascendente por longitud.
 */
function reoptMatrixProvider(): RoadMatrixProvider
{
    return new class implements RoadMatrixProvider
    {
        public function matrixFor(array $points): DistanceMatrix
        {
            $grid = [];
            foreach ($points as $from) { // NOSONAR: FP de data-flow de Sonar sobre el foreach en la clase anónima de test.
                $row = [];
                foreach ($points as $to) { // NOSONAR: FP de data-flow de Sonar sobre el foreach en la clase anónima de test.
                    $row[] = abs($from->longitude - $to->longitude) * 100_000.0;
                }
                $grid[] = $row;
            }

            return DistanceMatrix::fromArrays($grid, $grid);
        }
    };
}

/**
 * Proveedor de geometría configurable: devuelve una geometría fija o null (degradación).
 */
function reoptGeometryProvider(?RouteGeometry $geometry): RouteGeometryProvider
{
    return new class($geometry) implements RouteGeometryProvider
    {
        public function __construct(private ?RouteGeometry $geometry) {}

        public function geometryFor(array $orderedPoints): ?RouteGeometry
        {
            return $this->geometry;
        }
    };
}

/**
 * RecordDomainEvents real sobre un outbox en memoria que cuenta los mensajes agregados.
 */
function reoptRecordEvents(): array
{
    $store = new class implements OutboxStore
    {
        /** @var list<OutboxMessage> */
        public array $appended = [];

        public function append(OutboxMessage $message): void
        {
            $this->appended[] = $message;
        }

        public function pullBatch(int $limit, DateTimeImmutable $now): array
        {
            return [];
        }

        public function save(OutboxMessage $message): void {}
    };

    $clock = new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-10-13T07:30:00+00:00');
        }
    };

    return [new RecordDomainEvents($store, $clock), $store];
}

/**
 * Plan en borrador con tres paradas en un orden subóptimo (A, B, C por longitud 0.30, 0.10, 0.20).
 */
function reoptDraftPlan(): DeliveryPlan
{
    $plan = DeliveryPlan::startDraft(PlanId::generate(), PlanDate::fromString('2026-10-13'), 'veh-1');
    $plan->assignShipment(PlannedStopId::generate(), 'shp-A');
    $plan->assignShipment(PlannedStopId::generate(), 'shp-B');
    $plan->assignShipment(PlannedStopId::generate(), 'shp-C');

    return $plan;
}

it('re-sequences the plan, attaches geometry, saves it and records the event', function (): void {
    $plan = reoptDraftPlan();
    $plan->attachRouteGeometry(RouteGeometry::of([[0.0, 0.0], [1.0, 1.0]], 50.0, 30.0));

    $repository = reoptPlanRepository();
    $geometry = RouteGeometry::of([[0.0, 0.0], [-68.10, -16.5], [-68.20, -16.5], [-68.30, -16.5]], 900.0, 600.0);
    [$recorder, $store] = reoptRecordEvents();

    $reoptimizer = new RouteReoptimizer(
        $repository,
        reoptMatrixProvider(),
        reoptGeometryProvider($geometry),
        new RouteSequencer,
        $recorder,
    );

    $coordinates = [
        'shp-A' => [-16.5, -68.30, null],
        'shp-B' => [-16.5, -68.10, null],
        'shp-C' => [-16.5, -68.20, null],
    ];

    $reoptimizer->reoptimize(
        $plan,
        GeoPoint::of(-16.5, 0.0),
        $coordinates,
        new DateTimeImmutable('2026-10-13T07:30:00+00:00'),
        'incident',
    );

    $ordered = array_map(static fn ($stop): string => $stop->shipmentId(), $plan->stops());

    expect($ordered)->toBe(['shp-B', 'shp-C', 'shp-A'])
        ->and($plan->geometry())->toBe($geometry)
        ->and($repository->saved)->toBe($plan)
        ->and($store->appended)->toHaveCount(1)
        ->and($plan->stops()[0]->eta())->not->toBeNull();
});

it('leaves the geometry untouched when the provider degrades to null', function (): void {
    $plan = reoptDraftPlan();
    $repository = reoptPlanRepository();
    [$recorder] = reoptRecordEvents();

    $reoptimizer = new RouteReoptimizer(
        $repository,
        reoptMatrixProvider(),
        reoptGeometryProvider(null),
        new RouteSequencer,
        $recorder,
    );

    $coordinates = [
        'shp-A' => [-16.5, -68.30, null],
        'shp-B' => [-16.5, -68.10, null],
        'shp-C' => [-16.5, -68.20, null],
    ];

    $reoptimizer->reoptimize(
        $plan,
        GeoPoint::of(-16.5, 0.0),
        $coordinates,
        new DateTimeImmutable('2026-10-13T07:30:00+00:00'),
        'manual',
    );

    expect($plan->geometry())->toBeNull()
        ->and($repository->saved)->toBe($plan);
});

it('rejects a reoptimization when a stop has no coordinate', function (): void {
    $plan = reoptDraftPlan();
    [$recorder] = reoptRecordEvents();

    $reoptimizer = new RouteReoptimizer(
        reoptPlanRepository(),
        reoptMatrixProvider(),
        reoptGeometryProvider(null),
        new RouteSequencer,
        $recorder,
    );

    // Falta shp-C en las coordenadas.
    $coordinates = [
        'shp-A' => [-16.5, -68.30, null],
        'shp-B' => [-16.5, -68.10, null],
    ];

    expect(fn () => $reoptimizer->reoptimize(
        $plan,
        GeoPoint::of(-16.5, 0.0),
        $coordinates,
        new DateTimeImmutable('2026-10-13T07:30:00+00:00'),
        'manual',
    ))->toThrow(PlanningException::class, 'falta la coordenada');
});

it('rejects re-optimizing a plan with no stops', function (): void {
    $plan = DeliveryPlan::startDraft(PlanId::generate(), PlanDate::fromString('2026-10-13'), 'veh-1');
    [$recorder] = reoptRecordEvents();

    $reoptimizer = new RouteReoptimizer(
        reoptPlanRepository(),
        reoptMatrixProvider(),
        reoptGeometryProvider(null),
        new RouteSequencer,
        $recorder,
    );

    expect(fn () => $reoptimizer->reoptimize(
        $plan,
        GeoPoint::of(-16.5, 0.0),
        [],
        new DateTimeImmutable('2026-10-13T07:30:00+00:00'),
        'manual',
    ))->toThrow(PlanningException::class, 'sin paradas');
});
