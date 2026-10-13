<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Event\PlanProposed;
use Logistics\Planning\Domain\Event\PlanPublished;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\Model\DeliveryPlan;
use Logistics\Planning\Domain\ValueObject\Eta;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Planning\Domain\ValueObject\PlannedStopId;
use Logistics\Planning\Domain\ValueObject\PlanStatus;

/**
 * Abre un borrador de ejemplo para un camión y un día.
 */
function startDraftPlan(): DeliveryPlan
{
    return DeliveryPlan::startDraft(
        PlanId::generate(),
        PlanDate::fromString('2026-10-13'),
        'veh-001',
    );
}

/**
 * Construye una ETA simple a partir de los minutos desde la salida (07:00).
 */
function etaAfter(int $minutes): Eta
{
    $arrival = (new DateTimeImmutable('2026-10-13T07:00:00+00:00'))->modify("+{$minutes} minutes");

    return Eta::at($arrival, $minutes);
}

/**
 * Asigna los envíos dados al plan, en orden.
 *
 * @param list<string> $shipmentIds
 */
function assignShipments(DeliveryPlan $plan, array $shipmentIds): void
{
    foreach ($shipmentIds as $shipmentId) {
        $plan->assignShipment(PlannedStopId::generate(), $shipmentId);
    }
}

it('starts a draft plan with no stops', function (): void {
    $plan = startDraftPlan();

    expect($plan->status())->toBe(PlanStatus::Draft)
        ->and($plan->vehicleId())->toBe('veh-001')
        ->and($plan->date()->toString())->toBe('2026-10-13')
        ->and($plan->stops())->toBe([])
        ->and($plan->releaseEvents())->toBe([]);
});

it('rejects an empty vehicle id', function (): void {
    expect(fn () => DeliveryPlan::startDraft(PlanId::generate(), PlanDate::fromString('2026-10-13'), '  '))
        ->toThrow(PlanningException::class, 'id de vehículo');
});

it('assigns shipments with ascending sequence', function (): void {
    $plan = startDraftPlan();
    assignShipments($plan, ['ship-a', 'ship-b', 'ship-c']);

    $stops = $plan->stops();

    expect($stops)->toHaveCount(3)
        ->and($stops[0]->shipmentId())->toBe('ship-a')
        ->and($stops[0]->sequence()->value)->toBe(1)
        ->and($stops[2]->shipmentId())->toBe('ship-c')
        ->and($stops[2]->sequence()->value)->toBe(3)
        ->and($stops[0]->eta())->toBeNull()
        ->and($stops[0]->lane())->toBeNull();
});

it('rejects assigning the same shipment twice', function (): void {
    $plan = startDraftPlan();
    $plan->assignShipment(PlannedStopId::generate(), 'ship-a');

    expect(fn () => $plan->assignShipment(PlannedStopId::generate(), 'ship-a'))
        ->toThrow(PlanningException::class, 'ya está en el plan');
});

it('proposes a route: reorders, sets ETA, becomes Proposed and records PlanProposed', function (): void {
    $plan = startDraftPlan();
    assignShipments($plan, ['ship-a', 'ship-b', 'ship-c']);

    // El motor devolvió el orden c, a, b.
    $plan->proposeRoute(
        ['ship-c', 'ship-a', 'ship-b'],
        ['ship-a' => etaAfter(40), 'ship-b' => etaAfter(60), 'ship-c' => etaAfter(20)],
    );

    $stops = $plan->stops();

    expect($plan->status())->toBe(PlanStatus::Proposed)
        ->and($stops[0]->shipmentId())->toBe('ship-c')
        ->and($stops[0]->sequence()->value)->toBe(1)
        ->and($stops[0]->eta()?->minutesFromDeparture)->toBe(20)
        ->and($stops[1]->shipmentId())->toBe('ship-a')
        ->and($stops[1]->sequence()->value)->toBe(2)
        ->and($stops[2]->shipmentId())->toBe('ship-b')
        ->and($stops[2]->sequence()->value)->toBe(3);

    $events = $plan->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PlanProposed::class);

    /** @var PlanProposed $event */
    $event = $events[0];
    expect($event->eventName())->toBe('planning.plan_proposed')
        ->and($event->stopCount)->toBe(3)
        ->and($event->vehicleId)->toBe('veh-001')
        ->and($event->payload()['plan_date'])->toBe('2026-10-13');
});

it('rejects proposing a route with a missing ETA', function (): void {
    $plan = startDraftPlan();
    assignShipments($plan, ['ship-a', 'ship-b']);

    expect(fn () => $plan->proposeRoute(['ship-a', 'ship-b'], ['ship-a' => etaAfter(20)]))
        ->toThrow(PlanningException::class, 'falta la ETA');
});

it('rejects an order that is not a permutation of the stops', function (): void {
    $plan = startDraftPlan();
    assignShipments($plan, ['ship-a', 'ship-b']);

    expect(fn () => $plan->proposeRoute(['ship-a', 'ship-x'], ['ship-a' => etaAfter(20), 'ship-x' => etaAfter(40)]))
        ->toThrow(PlanningException::class, 'no está en el plan');
});

it('reorders stops as a human adjustment, renumbering the sequence', function (): void {
    $plan = startDraftPlan();
    assignShipments($plan, ['ship-a', 'ship-b', 'ship-c']);
    $plan->proposeRoute(
        ['ship-a', 'ship-b', 'ship-c'],
        ['ship-a' => etaAfter(20), 'ship-b' => etaAfter(40), 'ship-c' => etaAfter(60)],
    );
    $plan->releaseEvents();

    $plan->reorderStops(['ship-c', 'ship-b', 'ship-a']);

    $stops = $plan->stops();
    expect($stops[0]->shipmentId())->toBe('ship-c')
        ->and($stops[0]->sequence()->value)->toBe(1)
        ->and($stops[2]->shipmentId())->toBe('ship-a')
        ->and($stops[2]->sequence()->value)->toBe(3)
        // La ETA viaja con la parada (sigue siendo la de ship-c).
        ->and($stops[0]->eta()?->minutesFromDeparture)->toBe(60);
});

it('recomputes the load plan: lane in reverse delivery order', function (): void {
    $plan = startDraftPlan();
    // Siete envíos para cruzar el límite de carril (6 bultos por carril).
    assignShipments($plan, ['s1', 's2', 's3', 's4', 's5', 's6', 's7']);
    $order = ['s1', 's2', 's3', 's4', 's5', 's6', 's7'];
    $etas = [];
    foreach ($order as $i => $id) {
        $etas[$id] = etaAfter(($i + 1) * 10);
    }
    $plan->proposeRoute($order, $etas);

    $plan->recomputeLoadPlan();

    $stops = $plan->stops();
    // La última entrega (sequence 7) se carga primero → orden de carga 1 → carril A.
    expect($stops[6]->sequence()->value)->toBe(7)
        ->and($stops[6]->lane()?->label)->toBe('A')
        // La primera entrega (sequence 1) se carga última → orden de carga 7 → carril B.
        ->and($stops[0]->sequence()->value)->toBe(1)
        ->and($stops[0]->lane()?->label)->toBe('B');
});

it('publishes a proposed plan and records PlanPublished', function (): void {
    $plan = startDraftPlan();
    assignShipments($plan, ['ship-a', 'ship-b']);
    $plan->proposeRoute(['ship-a', 'ship-b'], ['ship-a' => etaAfter(20), 'ship-b' => etaAfter(40)]);
    $plan->releaseEvents();

    $plan->publish();

    expect($plan->status())->toBe(PlanStatus::Published);

    $events = $plan->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PlanPublished::class);

    /** @var PlanPublished $event */
    $event = $events[0];
    expect($event->eventName())->toBe('planning.plan_published')
        ->and($event->stopCount)->toBe(2);
});

it('rejects publishing a plan that was not proposed', function (): void {
    $plan = startDraftPlan();
    assignShipments($plan, ['ship-a']);

    expect(fn () => $plan->publish())
        ->toThrow(PlanningException::class, 'solo se puede publicar un plan propuesto');
});

it('rejects assigning to a published plan', function (): void {
    $plan = startDraftPlan();
    assignShipments($plan, ['ship-a']);
    $plan->proposeRoute(['ship-a'], ['ship-a' => etaAfter(20)]);
    $plan->publish();

    expect(fn () => $plan->assignShipment(PlannedStopId::generate(), 'ship-b'))
        ->toThrow(PlanningException::class, 'ya publicado');
});

it('reconstitutes without recording events', function (): void {
    $plan = startDraftPlan();
    assignShipments($plan, ['ship-a', 'ship-b']);
    $plan->proposeRoute(['ship-a', 'ship-b'], ['ship-a' => etaAfter(20), 'ship-b' => etaAfter(40)]);
    $plan->releaseEvents();

    $reloaded = DeliveryPlan::reconstitute(
        $plan->id(),
        $plan->date(),
        $plan->vehicleId(),
        $plan->status(),
        $plan->stops(),
    );

    expect($reloaded->releaseEvents())->toBe([])
        ->and($reloaded->status())->toBe(PlanStatus::Proposed)
        ->and($reloaded->stops())->toHaveCount(2)
        ->and($reloaded->id()->equals($plan->id()))->toBeTrue();
});
