<?php

declare(strict_types=1);

use Logistics\Tracking\Domain\Model\ShipmentTracking;
use Logistics\Tracking\Domain\ValueObject\GeoPoint;
use Logistics\Tracking\Domain\ValueObject\TrackingId;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Logistics\Tracking\Domain\ValueObject\TruckPosition;

function trackingForGps(): ShipmentTracking
{
    return ShipmentTracking::open(
        TrackingId::generate(),
        'd290f1ee-6c54-4b01-90e6-d701748f0851',
        TrackingToken::generate(),
        2,
    );
}

it('applies a fresh position and reports it was applied', function (): void {
    $tracking = trackingForGps();

    $applied = $tracking->updatePosition(TruckPosition::at(
        GeoPoint::of(-16.5, -68.15),
        new DateTimeImmutable('2026-10-13T12:00:00+00:00'),
    ));

    expect($applied)->toBeTrue()
        ->and($tracking->lastPosition()?->reportedAt->format(DATE_ATOM))->toBe('2026-10-13T12:00:00+00:00');
});

it('discards an out-of-order position older than the last known one', function (): void {
    $tracking = trackingForGps();
    $tracking->updatePosition(TruckPosition::at(
        GeoPoint::of(-16.5, -68.15),
        new DateTimeImmutable('2026-10-13T12:00:00+00:00'),
    ));

    // Llega un reporte más viejo (buffer offline, reintento del relay): se descarta.
    $applied = $tracking->updatePosition(TruckPosition::at(
        GeoPoint::of(-17.0, -67.0),
        new DateTimeImmutable('2026-10-13T11:00:00+00:00'),
    ));

    expect($applied)->toBeFalse()
        ->and($tracking->lastPosition()?->point->latitude)->toBe(-16.5)
        ->and($tracking->lastPosition()?->reportedAt->format(DATE_ATOM))->toBe('2026-10-13T12:00:00+00:00');
});

it('considers a recent position live and an old one stale', function (): void {
    $now = new DateTimeImmutable('2026-10-13T12:05:00+00:00');

    $fresh = TruckPosition::at(GeoPoint::of(-16.5, -68.15), new DateTimeImmutable('2026-10-13T12:03:00+00:00'));
    $stale = TruckPosition::at(GeoPoint::of(-16.5, -68.15), new DateTimeImmutable('2026-10-13T11:50:00+00:00'));

    expect($fresh->isLiveAt($now))->toBeTrue()
        ->and($stale->isLiveAt($now))->toBeFalse();
});

it('treats a future-dated reading as not live', function (): void {
    $now = new DateTimeImmutable('2026-10-13T12:00:00+00:00');
    $future = TruckPosition::at(GeoPoint::of(-16.5, -68.15), new DateTimeImmutable('2026-10-13T12:10:00+00:00'));

    expect($future->isLiveAt($now))->toBeFalse();
});
