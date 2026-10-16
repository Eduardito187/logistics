<?php

declare(strict_types=1);

use Logistics\Network\Domain\Event\ReceivingDockRegistered;
use Logistics\Network\Domain\Exception\NetworkException;
use Logistics\Network\Domain\ReceivingDock;
use Logistics\Network\Domain\ValueObject\DockCapacity;
use Logistics\Network\Domain\ValueObject\DockCode;
use Logistics\Network\Domain\ValueObject\NodeId;
use Logistics\Network\Domain\ValueObject\NodeType;
use Logistics\Network\Domain\ValueObject\ReceivingDockId;

it('registers a dock active and records ReceivingDockRegistered', function (): void {
    $id = ReceivingDockId::generate();
    $warehouse = NodeId::generate();

    $dock = ReceivingDock::register(
        $id,
        $warehouse,
        DockCode::fromString('dock-01'),
        'North dock',
        DockCapacity::of(4),
    );

    expect($dock->isActive())->toBeTrue()
        ->and($dock->code()->toString())->toBe('DOCK-01')
        ->and($dock->name())->toBe('North dock')
        ->and($dock->capacity()->appointmentsPerSlot)->toBe(4)
        ->and($dock->warehouseId()->equals($warehouse))->toBeTrue();

    $events = $dock->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ReceivingDockRegistered::class)
        ->and($events[0]->eventName())->toBe('network.receiving_dock_registered')
        ->and($events[0]->payload())->toBe([
            'warehouse_id' => $warehouse->toString(),
            'code' => 'DOCK-01',
            'name' => 'North dock',
            'appointments_per_slot' => 4,
        ]);
});

it('rejects a blank dock name', function (): void {
    expect(fn () => ReceivingDock::register(
        ReceivingDockId::generate(),
        NodeId::generate(),
        DockCode::fromString('A1'),
        '   ',
        DockCapacity::of(1),
    ))->toThrow(NetworkException::class);
});

it('rehydrates from state without recording events', function (): void {
    $dock = ReceivingDock::fromState(
        ReceivingDockId::generate(),
        NodeId::generate(),
        DockCode::fromString('A1'),
        'Dock A1',
        DockCapacity::of(2),
        false,
    );

    expect($dock->isActive())->toBeFalse()
        ->and($dock->pendingEvents())->toHaveCount(0);
});

it('rejects a dock capacity below one', function (): void {
    expect(fn () => DockCapacity::of(0))->toThrow(NetworkException::class);
});

it('rejects a malformed dock code', function (): void {
    expect(fn () => DockCode::fromString('bad code'))->toThrow(NetworkException::class);
});

it('marks only the central warehouse as inbound-capable', function (): void {
    expect(NodeType::CentralWarehouse->receivesInbound())->toBeTrue()
        ->and(NodeType::DistributionCenter->receivesInbound())->toBeFalse()
        ->and(NodeType::CentralWarehouse->label())->toBe('Central warehouse');
});
