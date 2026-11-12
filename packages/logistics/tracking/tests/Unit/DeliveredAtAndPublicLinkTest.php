<?php

declare(strict_types=1);

use Logistics\Tracking\Domain\Model\ShipmentTracking;
use Logistics\Tracking\Domain\ValueObject\TrackingId;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

// Regla del dominio: la entrega fija delivered_at (una sola vez) y el enlace público vence contando el TTL
// DESDE LA ENTREGA; un pedido no entregado nunca vence por TTL.

function trackingOnTheWayForLink(?DateTimeImmutable $deliveredAt = null, VisibleStatus $status = VisibleStatus::OnTheWay): ShipmentTracking
{
    return ShipmentTracking::reconstitute(
        TrackingId::generate(),
        'ship-link-1',
        TrackingToken::generate(),
        $status,
        0,
        null,
        null,
        null,
        null,
        'order-link-1',
        $deliveredAt,
    );
}

it('sets deliveredAt from the fact time on delivery and keeps the first value on replays', function (): void {
    $tracking = trackingOnTheWayForLink();
    $at = new DateTimeImmutable('2026-11-01T10:00:00+00:00');

    $tracking->applyStatus(VisibleStatus::Delivered, $at);
    expect($tracking->deliveredAt())->toEqual($at);

    // Reenvío del mismo hecho con otra hora: no mueve la fecha original.
    $tracking->applyStatus(VisibleStatus::Delivered, new DateTimeImmutable('2026-11-02T10:00:00+00:00'));
    expect($tracking->deliveredAt())->toEqual($at);

    // Una devolución posterior conserva la fecha de entrega.
    $tracking->markReturned();
    expect($tracking->deliveredAt())->toEqual($at);
});

it('completes a missing deliveredAt when an already delivered tracking receives the fact again', function (): void {
    $tracking = trackingOnTheWayForLink(null, VisibleStatus::Delivered);
    $at = new DateTimeImmutable('2026-11-01T10:00:00+00:00');

    $tracking->applyStatus(VisibleStatus::Delivered, $at);

    expect($tracking->deliveredAt())->toEqual($at)
        ->and($tracking->releaseEvents())->toBe([]);
});

it('expires the public link only after the TTL counted from delivery', function (): void {
    $delivered = new DateTimeImmutable('2026-11-01T10:00:00+00:00');
    $tracking = trackingOnTheWayForLink($delivered, VisibleStatus::Delivered);

    expect($tracking->publicLinkExpiresAt(72))->toEqual(new DateTimeImmutable('2026-11-04T10:00:00+00:00'))
        ->and($tracking->isPublicLinkExpiredAt(new DateTimeImmutable('2026-11-04T09:59:59+00:00'), 72))->toBeFalse()
        ->and($tracking->isPublicLinkExpiredAt(new DateTimeImmutable('2026-11-04T10:00:00+00:00'), 72))->toBeTrue()
        // TTL <= 0 desactiva el vencimiento.
        ->and($tracking->isPublicLinkExpiredAt(new DateTimeImmutable('2030-01-01T00:00:00+00:00'), 0))->toBeFalse();
});

it('never expires the public link of an order that was not delivered', function (): void {
    $tracking = trackingOnTheWayForLink();

    expect($tracking->publicLinkExpiresAt(72))->toBeNull()
        ->and($tracking->isPublicLinkExpiredAt(new DateTimeImmutable('2030-01-01T00:00:00+00:00'), 72))->toBeFalse();
});
