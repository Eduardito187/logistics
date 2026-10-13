<?php

declare(strict_types=1);

use Logistics\Network\Domain\Event\NodeDeactivated;
use Logistics\Network\Domain\Event\NodeRegistered;
use Logistics\Network\Domain\Exception\NetworkException;
use Logistics\Network\Domain\Node;
use Logistics\Network\Domain\ValueObject\Department;
use Logistics\Network\Domain\ValueObject\GeoPoint;
use Logistics\Network\Domain\ValueObject\NodeCode;
use Logistics\Network\Domain\ValueObject\NodeId;
use Logistics\Network\Domain\ValueObject\NodeStatus;
use Logistics\Network\Domain\ValueObject\NodeType;

function registerNode(): Node
{
    return Node::register(
        NodeId::generate(),
        NodeCode::fromString('CD-LP-01'),
        'Centro La Paz',
        NodeType::DistributionCenter,
        GeoPoint::of(-16.5, -68.15),
    );
}

it('registers an active node and records the NodeRegistered event', function (): void {
    $node = registerNode();

    expect($node->status())->toBe(NodeStatus::Active)
        ->and($node->isActive())->toBeTrue()
        ->and($node->code()->toString())->toBe('CD-LP-01')
        ->and($node->type())->toBe(NodeType::DistributionCenter);

    $events = $node->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(NodeRegistered::class)
        ->and($events[0]->eventName())->toBe('network.node_registered')
        ->and($events[0]->aggregateId())->toBe($node->id()->toString())
        ->and($events[0]->payload())->toBe([
            'code' => 'CD-LP-01',
            'name' => 'Centro La Paz',
            'type' => 'distribution_center',
            'lat' => -16.5,
            'lng' => -68.15,
            'department' => null,
        ]);
});

it('registers a node with a department and carries it in the event payload', function (): void {
    $node = Node::register(
        NodeId::generate(),
        NodeCode::fromString('CD-LP-01'),
        'Centro La Paz',
        NodeType::DistributionCenter,
        GeoPoint::of(-16.5, -68.15),
        Department::LaPaz,
    );

    expect($node->department())->toBe(Department::LaPaz);

    $events = $node->releaseEvents();
    expect($events[0]->payload()['department'])->toBe('la_paz');
});

it('leaves the department null when none is given', function (): void {
    expect(registerNode()->department())->toBeNull();
});

it('assigns and clears the department without recording an event', function (): void {
    $node = registerNode();
    $node->releaseEvents();

    $node->assignDepartment(Department::SantaCruz);
    expect($node->department())->toBe(Department::SantaCruz)
        ->and($node->releaseEvents())->toHaveCount(0);

    $node->assignDepartment(null);
    expect($node->department())->toBeNull()
        ->and($node->releaseEvents())->toHaveCount(0);
});

it('rejects a blank name', function (): void {
    expect(fn () => Node::register(
        NodeId::generate(),
        NodeCode::fromString('CD-LP-01'),
        '   ',
        NodeType::DistributionCenter,
        GeoPoint::of(-16.5, -68.15),
    ))->toThrow(NetworkException::class, 'nombre');
});

it('deactivates a node and records the NodeDeactivated event once', function (): void {
    $node = registerNode();
    $node->releaseEvents();

    $node->deactivate();

    expect($node->status())->toBe(NodeStatus::Inactive)
        ->and($node->isActive())->toBeFalse();

    $events = $node->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(NodeDeactivated::class)
        ->and($events[0]->eventName())->toBe('network.node_deactivated');

    // Desactivar de nuevo es idempotente: ni cambia el estado ni graba otro evento.
    $node->deactivate();
    expect($node->releaseEvents())->toHaveCount(0);
});

it('reactivates a node without recording an event', function (): void {
    $node = registerNode();
    $node->releaseEvents();
    $node->deactivate();
    $node->releaseEvents();

    $node->activate();

    expect($node->status())->toBe(NodeStatus::Active)
        ->and($node->releaseEvents())->toHaveCount(0);
});

it('relocates a node to a new point', function (): void {
    $node = registerNode();
    $node->releaseEvents();

    $node->relocate(GeoPoint::of(-17.78, -63.18));

    expect($node->location()->latitude)->toBe(-17.78)
        ->and($node->location()->longitude)->toBe(-63.18)
        ->and($node->releaseEvents())->toHaveCount(0);
});

it('rehydrates from state without recording events', function (): void {
    $node = Node::fromState(
        NodeId::generate(),
        NodeCode::fromString('T-SCZ-03'),
        'Tienda Santa Cruz',
        NodeType::Store,
        GeoPoint::of(-17.78, -63.18),
        NodeStatus::Inactive,
    );

    expect($node->status())->toBe(NodeStatus::Inactive)
        ->and($node->pendingEvents())->toHaveCount(0);
});
