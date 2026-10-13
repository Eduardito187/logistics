<?php

declare(strict_types=1);

use Logistics\Network\Domain\Event\LegDefined;
use Logistics\Network\Domain\Event\LegRemoved;
use Logistics\Network\Domain\Exception\NetworkException;
use Logistics\Network\Domain\Leg;
use Logistics\Network\Domain\ValueObject\CorridorType;
use Logistics\Network\Domain\ValueObject\LegId;
use Logistics\Network\Domain\ValueObject\NodeId;

it('defines a leg between two nodes and records the LegDefined event', function (): void {
    $from = NodeId::generate();
    $to = NodeId::generate();

    $leg = Leg::define(LegId::generate(), $from, $to, CorridorType::CdToStore);

    expect($leg->from()->equals($from))->toBeTrue()
        ->and($leg->to()->equals($to))->toBeTrue()
        ->and($leg->corridor())->toBe(CorridorType::CdToStore);

    $events = $leg->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(LegDefined::class)
        ->and($events[0]->eventName())->toBe('network.leg_defined')
        ->and($events[0]->aggregateId())->toBe($leg->id()->toString())
        ->and($events[0]->payload())->toBe([
            'from_node_id' => $from->toString(),
            'to_node_id' => $to->toString(),
            'corridor_type' => 'cd_to_store',
        ]);
});

it('rejects a leg with the same origin and destination node', function (): void {
    $node = NodeId::generate();

    expect(fn () => Leg::define(LegId::generate(), $node, $node, CorridorType::CdToCd))
        ->toThrow(NetworkException::class, 'origen');
});

it('records the LegRemoved event when removed', function (): void {
    $leg = Leg::define(LegId::generate(), NodeId::generate(), NodeId::generate(), CorridorType::StoreToStore);
    $leg->releaseEvents();

    $leg->remove();

    $events = $leg->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(LegRemoved::class)
        ->and($events[0]->eventName())->toBe('network.leg_removed')
        ->and($events[0]->payload())->toBe([]);
});

it('rehydrates from state without recording events', function (): void {
    $leg = Leg::fromState(
        LegId::generate(),
        NodeId::generate(),
        NodeId::generate(),
        CorridorType::StorePickup,
    );

    expect($leg->corridor())->toBe(CorridorType::StorePickup)
        ->and($leg->pendingEvents())->toHaveCount(0);
});
