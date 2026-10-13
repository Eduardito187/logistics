<?php

declare(strict_types=1);

use Logistics\Tracking\Domain\Event\TrackingOpened;
use Logistics\Tracking\Domain\Event\TrackingStatusChanged;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\Model\ShipmentTracking;
use Logistics\Tracking\Domain\ValueObject\Eta;
use Logistics\Tracking\Domain\ValueObject\GeoPoint;
use Logistics\Tracking\Domain\ValueObject\TrackingId;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Logistics\Tracking\Domain\ValueObject\TruckPosition;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

function openTracking(int $remainingStops = 3): ShipmentTracking
{
    return ShipmentTracking::open(
        TrackingId::generate(),
        'd290f1ee-6c54-4b01-90e6-d701748f0851',
        TrackingToken::generate(),
        $remainingStops,
    );
}

it('opens a tracking in Registered with a token and records TrackingOpened', function (): void {
    $token = TrackingToken::generate();
    $id = TrackingId::generate();

    $tracking = ShipmentTracking::open($id, 'ship-123', $token, 4);

    expect($tracking->status())->toBe(VisibleStatus::Registered)
        ->and($tracking->shipmentId())->toBe('ship-123')
        ->and($tracking->token()->equals($token))->toBeTrue()
        ->and($tracking->remainingStops())->toBe(4)
        ->and($tracking->eta())->toBeNull()
        ->and($tracking->lastPosition())->toBeNull();

    $events = $tracking->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(TrackingOpened::class)
        ->and($events[0]->eventName())->toBe('tracking.tracking_opened')
        ->and($events[0]->aggregateId())->toBe($id->toString())
        ->and($events[0]->payload())->toBe([
            'tracking_id' => $id->toString(),
            'shipment_id' => 'ship-123',
            'token' => $token->toString(),
            'status' => 'REGISTERED',
            'remaining_stops' => 4,
        ]);
});

it('rejects a blank shipment id', function (): void {
    expect(fn () => ShipmentTracking::open(
        TrackingId::generate(),
        '   ',
        TrackingToken::generate(),
        0,
    ))->toThrow(TrackingException::class, 'envío');
});

it('rejects negative remaining stops', function (): void {
    expect(fn () => ShipmentTracking::open(
        TrackingId::generate(),
        'ship-1',
        TrackingToken::generate(),
        -1,
    ))->toThrow(TrackingException::class, 'negativas');
});

it('applies a new status and records TrackingStatusChanged', function (): void {
    $tracking = openTracking();
    $tracking->releaseEvents();

    $tracking->applyStatus(VisibleStatus::OnTheWay);

    expect($tracking->status())->toBe(VisibleStatus::OnTheWay);

    $events = $tracking->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(TrackingStatusChanged::class)
        ->and($events[0]->eventName())->toBe('tracking.status_changed')
        ->and($events[0]->payload()['previous_status'])->toBe('REGISTERED')
        ->and($events[0]->payload()['status'])->toBe('ON_THE_WAY');
});

it('ignores applying the same status: no event', function (): void {
    $tracking = openTracking();
    $tracking->releaseEvents();

    $tracking->applyStatus(VisibleStatus::Registered);

    expect($tracking->status())->toBe(VisibleStatus::Registered)
        ->and($tracking->releaseEvents())->toHaveCount(0);
});

it('does not allow leaving a terminal status', function (): void {
    $tracking = openTracking();
    $tracking->applyStatus(VisibleStatus::Delivered);
    $tracking->releaseEvents();

    expect(fn () => $tracking->applyStatus(VisibleStatus::OnTheWay))
        ->toThrow(TrackingException::class, 'terminal');
});

it('updates the truck position without recording an event', function (): void {
    $tracking = openTracking();
    $tracking->releaseEvents();

    $position = TruckPosition::at(
        GeoPoint::of(-16.5, -68.15),
        new DateTimeImmutable('2026-10-13T12:00:00+00:00'),
    );
    $tracking->updatePosition($position);

    expect($tracking->lastPosition()?->equals($position))->toBeTrue()
        ->and($tracking->releaseEvents())->toHaveCount(0);
});

it('sets the ETA without recording an event', function (): void {
    $tracking = openTracking();
    $tracking->releaseEvents();

    $eta = Eta::at(new DateTimeImmutable('2026-10-13T15:30:00+00:00'));
    $tracking->setEta($eta);

    expect($tracking->eta()?->equals($eta))->toBeTrue()
        ->and($tracking->releaseEvents())->toHaveCount(0);
});

it('decrements remaining stops and never goes below zero', function (): void {
    $tracking = openTracking(2);
    $tracking->releaseEvents();

    $tracking->decrementStops();
    expect($tracking->remainingStops())->toBe(1);

    $tracking->decrementStops();
    expect($tracking->remainingStops())->toBe(0);

    // No baja de cero.
    $tracking->decrementStops();
    expect($tracking->remainingStops())->toBe(0)
        ->and($tracking->releaseEvents())->toHaveCount(0);
});

it('rehydrates from state without recording events', function (): void {
    $tracking = ShipmentTracking::reconstitute(
        TrackingId::generate(),
        'ship-9',
        TrackingToken::generate(),
        VisibleStatus::Arriving,
        1,
        null,
        null,
    );

    expect($tracking->status())->toBe(VisibleStatus::Arriving)
        ->and($tracking->pendingEvents())->toHaveCount(0);
});
