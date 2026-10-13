<?php

declare(strict_types=1);

use Logistics\Tracking\Domain\Event\UnitStatusChanged;
use Logistics\Tracking\Domain\Event\UnitTrackingOpened;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\Model\UnitTracking;
use Logistics\Tracking\Domain\ValueObject\UnitStatus;
use Logistics\Tracking\Domain\ValueObject\UnitTrackingId;

function openUnit(): UnitTracking
{
    return UnitTracking::open(
        UnitTrackingId::generate(),
        'd290f1ee-6c54-4b01-90e6-d701748f0851',
        'a1b2c3d4-0000-7000-8000-000000000001',
    );
}

it('opens a unit tracking in Registered and records UnitTrackingOpened', function (): void {
    $id = UnitTrackingId::generate();

    $unit = UnitTracking::open($id, 'ship-1', 'unit-1');

    expect($unit->status())->toBe(UnitStatus::Registered)
        ->and($unit->shipmentId())->toBe('ship-1')
        ->and($unit->unitId())->toBe('unit-1')
        ->and($unit->lastLocationId())->toBeNull()
        ->and($unit->lastSeenAt())->toBeNull();

    $events = $unit->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(UnitTrackingOpened::class)
        ->and($events[0]->eventName())->toBe('tracking.unit_tracking_opened')
        ->and($events[0]->payload())->toBe([
            'unit_tracking_id' => $id->toString(),
            'shipment_id' => 'ship-1',
            'unit_id' => 'unit-1',
            'status' => 'REGISTERED',
        ]);
});

it('rejects a blank shipment id or unit id', function (): void {
    expect(fn () => UnitTracking::open(UnitTrackingId::generate(), '  ', 'unit-1'))
        ->toThrow(TrackingException::class, 'envío');

    expect(fn () => UnitTracking::open(UnitTrackingId::generate(), 'ship-1', '  '))
        ->toThrow(TrackingException::class, 'bulto');
});

it('applies a new status, tracks location and time, and records UnitStatusChanged', function (): void {
    $unit = openUnit();
    $unit->releaseEvents();

    $changed = $unit->applyStatus(UnitStatus::Loaded, 'node-7', new DateTimeImmutable('2026-10-13T12:00:00+00:00'));

    expect($changed)->toBeTrue()
        ->and($unit->status())->toBe(UnitStatus::Loaded)
        ->and($unit->lastLocationId())->toBe('node-7')
        ->and($unit->lastSeenAt()?->format(DATE_ATOM))->toBe('2026-10-13T12:00:00+00:00');

    $events = $unit->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(UnitStatusChanged::class)
        ->and($events[0]->payload()['previous_status'])->toBe('REGISTERED')
        ->and($events[0]->payload()['status'])->toBe('LOADED');
});

it('is idempotent: applying the same status records no event', function (): void {
    $unit = openUnit();
    $unit->applyStatus(UnitStatus::Prepared, null, new DateTimeImmutable('2026-10-13T10:00:00+00:00'));
    $unit->releaseEvents();

    $changed = $unit->applyStatus(UnitStatus::Prepared, null, new DateTimeImmutable('2026-10-13T11:00:00+00:00'));

    expect($changed)->toBeFalse()
        ->and($unit->releaseEvents())->toHaveCount(0);
});

it('discards out-of-order reports (older than the last seen)', function (): void {
    $unit = openUnit();
    $unit->applyStatus(UnitStatus::Loaded, null, new DateTimeImmutable('2026-10-13T12:00:00+00:00'));
    $unit->releaseEvents();

    // Llega un evento MÁS VIEJO: se descarta, el estado no retrocede.
    $changed = $unit->applyStatus(UnitStatus::Prepared, null, new DateTimeImmutable('2026-10-13T09:00:00+00:00'));

    expect($changed)->toBeFalse()
        ->and($unit->status())->toBe(UnitStatus::Loaded)
        ->and($unit->releaseEvents())->toHaveCount(0);
});

it('does not leave a terminal status', function (): void {
    $unit = openUnit();
    $unit->applyStatus(UnitStatus::Delivered, null, new DateTimeImmutable('2026-10-13T12:00:00+00:00'));
    $unit->releaseEvents();

    $changed = $unit->applyStatus(UnitStatus::Loaded, null, new DateTimeImmutable('2026-10-13T13:00:00+00:00'));

    expect($changed)->toBeFalse()
        ->and($unit->status())->toBe(UnitStatus::Delivered)
        ->and($unit->releaseEvents())->toHaveCount(0);
});

it('rehydrates from state without recording events', function (): void {
    $unit = UnitTracking::reconstitute(
        UnitTrackingId::generate(),
        'ship-9',
        'unit-9',
        UnitStatus::Received,
        'node-2',
        new DateTimeImmutable('2026-10-13T08:00:00+00:00'),
    );

    expect($unit->status())->toBe(UnitStatus::Received)
        ->and($unit->lastLocationId())->toBe('node-2')
        ->and($unit->pendingEvents())->toHaveCount(0);
});
