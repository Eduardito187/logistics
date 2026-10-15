<?php

declare(strict_types=1);

use Logistics\Network\Domain\Event\LegDefined;
use Logistics\Network\Domain\Event\LegRemoved;
use Logistics\Network\Domain\Exception\NetworkException;
use Logistics\Network\Domain\Leg;
use Logistics\Network\Domain\ValueObject\CorridorType;
use Logistics\Network\Domain\ValueObject\LegId;
use Logistics\Network\Domain\ValueObject\NodeId;
use Logistics\Network\Domain\ValueObject\NodeType;

it('defines a leg between two nodes and records the LegDefined event', function (): void {
    $from = NodeId::generate();
    $to = NodeId::generate();

    $leg = Leg::define(
        LegId::generate(),
        $from,
        NodeType::DistributionCenter,
        $to,
        NodeType::Store,
        CorridorType::CdToStore,
    );

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

    expect(fn () => Leg::define(
        LegId::generate(),
        $node,
        NodeType::DistributionCenter,
        $node,
        NodeType::DistributionCenter,
        CorridorType::CdToCd,
    ))->toThrow(NetworkException::class, 'origen');
});

it('rejects a terminal corridor as a leg (it ends at the customer, not a node)', function (): void {
    expect(fn () => Leg::define(
        LegId::generate(),
        NodeId::generate(),
        NodeType::DistributionCenter,
        NodeId::generate(),
        NodeType::PickupPoint,
        CorridorType::CdToCustomer,
    ))->toThrow(NetworkException::class, 'acaba en el cliente');

    expect(fn () => Leg::define(
        LegId::generate(),
        NodeId::generate(),
        NodeType::Store,
        NodeId::generate(),
        NodeType::PickupPoint,
        CorridorType::StorePickup,
    ))->toThrow(NetworkException::class, 'acaba en el cliente');
});

it('rejects a leg whose origin node type does not match the corridor', function (): void {
    // CdToStore exige un CD de origen; una tienda de origen es inválida.
    expect(fn () => Leg::define(
        LegId::generate(),
        NodeId::generate(),
        NodeType::Store,
        NodeId::generate(),
        NodeType::Store,
        CorridorType::CdToStore,
    ))->toThrow(NetworkException::class, 'no corresponden al corredor');
});

it('rejects a leg whose destination node type does not match the corridor', function (): void {
    // StoreToCd exige un CD de destino; una tienda de destino es inválida.
    expect(fn () => Leg::define(
        LegId::generate(),
        NodeId::generate(),
        NodeType::Store,
        NodeId::generate(),
        NodeType::Store,
        CorridorType::StoreToCd,
    ))->toThrow(NetworkException::class, 'no corresponden al corredor');
});

it('accepts each of the four inter-node corridors with matching endpoints', function (
    CorridorType $corridor,
    NodeType $fromType,
    NodeType $toType,
): void {
    $leg = Leg::define(LegId::generate(), NodeId::generate(), $fromType, NodeId::generate(), $toType, $corridor);

    expect($leg->corridor())->toBe($corridor);
})->with([
    'cd to cd' => [CorridorType::CdToCd, NodeType::DistributionCenter, NodeType::DistributionCenter],
    'cd to store' => [CorridorType::CdToStore, NodeType::DistributionCenter, NodeType::Store],
    'store to store' => [CorridorType::StoreToStore, NodeType::Store, NodeType::Store],
    'store to cd' => [CorridorType::StoreToCd, NodeType::Store, NodeType::DistributionCenter],
]);

it('records the LegRemoved event when removed', function (): void {
    $leg = Leg::define(
        LegId::generate(),
        NodeId::generate(),
        NodeType::Store,
        NodeId::generate(),
        NodeType::Store,
        CorridorType::StoreToStore,
    );
    $leg->releaseEvents();

    $leg->remove();

    $events = $leg->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(LegRemoved::class)
        ->and($events[0]->eventName())->toBe('network.leg_removed')
        ->and($events[0]->payload())->toBe([]);
});

it('rehydrates from state without recording events or validating endpoints', function (): void {
    $leg = Leg::fromState(
        LegId::generate(),
        NodeId::generate(),
        NodeId::generate(),
        CorridorType::StorePickup,
    );

    expect($leg->corridor())->toBe(CorridorType::StorePickup)
        ->and($leg->pendingEvents())->toHaveCount(0);
});
