<?php

declare(strict_types=1);

use Logistics\Execution\Domain\Event\DropshipDelivered;
use Logistics\Execution\Domain\Event\DropshipDispatchConfirmed;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Execution\Domain\ValueObject\DeliveryId;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\DropshipHandling;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Shared\Domain\ValueObject\FulfillmentMode;

/**
 * Rama dropship del agregado Delivery (ADITIVA). Verifica que:
 *   - OWNED y FBD siguen ejecutando con escaneos propios (intactos);
 *   - dropship sin última milla propia (TrackOnly/ThirdPartyCarrier) RECHAZA los escaneos propios y se
 *     cierra por confirmación del seller (idempotente);
 *   - dropship con última milla (LastMile) sí admite los escaneos propios.
 * Helpers con prefijo dropship* para no colisionar con los globales de DeliveryTest (Pest un solo proceso).
 */
function dropshipStart(FulfillmentMode $mode, ?DropshipHandling $handling): Delivery
{
    return Delivery::start(
        DeliveryId::generate(),
        'a1111111-1111-7111-8111-111111111111',
        'b2222222-2222-7222-8222-222222222222',
        $mode,
        $handling,
    );
}

function dropshipGeo(): GeoPoint
{
    return GeoPoint::of(-16.5, -68.15);
}

function dropshipDevice(): DeviceContext
{
    return DeviceContext::of('device-01', 'user-07');
}

it('starts an owned delivery by default with the owned fulfillment mode and no handling', function (): void {
    $delivery = Delivery::start(
        DeliveryId::generate(),
        'a1111111-1111-7111-8111-111111111111',
        'b2222222-2222-7222-8222-222222222222',
    );

    expect($delivery->fulfillmentMode())->toBe(FulfillmentMode::Owned)
        ->and($delivery->dropshipHandling())->toBeNull();
});

it('keeps the FBD flow identical to owned: own scans are allowed', function (): void {
    $delivery = dropshipStart(FulfillmentMode::Fbd, null);

    $delivery->recordPreparationScan('u-1', dropshipGeo(), dropshipDevice(), new DateTimeImmutable('2026-10-16T08:00:00+00:00'), 'k-prep');
    $delivery->recordLoadScan('u-1', dropshipGeo(), dropshipDevice(), new DateTimeImmutable('2026-10-16T09:00:00+00:00'), 'k-load');

    expect($delivery->status())->toBe(DeliveryStatus::Loaded)
        ->and($delivery->scans())->toHaveCount(2);
});

it('requires a handling for dropship and forbids a handling for non-dropship', function (): void {
    expect(fn () => dropshipStart(FulfillmentMode::Dropship, null))
        ->toThrow(ExecutionException::class, 'requiere un modo de manejo');

    expect(fn () => dropshipStart(FulfillmentMode::Owned, DropshipHandling::LastMile))
        ->toThrow(ExecutionException::class, 'solo las entregas dropship');
});

it('rejects own scans on a track-only dropship delivery', function (): void {
    $delivery = dropshipStart(FulfillmentMode::Dropship, DropshipHandling::TrackOnly);

    expect(fn () => $delivery->recordPreparationScan('u-1', dropshipGeo(), dropshipDevice(), new DateTimeImmutable('2026-10-16T08:00:00+00:00'), 'k-prep'))
        ->toThrow(ExecutionException::class, 'no aplica en una entrega dropship');
});

it('rejects own scans on a third-party-carrier dropship delivery', function (): void {
    $delivery = dropshipStart(FulfillmentMode::Dropship, DropshipHandling::ThirdPartyCarrier);

    expect(fn () => $delivery->recordLoadScan('u-1', dropshipGeo(), dropshipDevice(), new DateTimeImmutable('2026-10-16T09:00:00+00:00'), 'k-load'))
        ->toThrow(ExecutionException::class, 'no aplica en una entrega dropship');
});

it('allows own scans on a dropship delivery when Dismac does the last mile', function (): void {
    $delivery = dropshipStart(FulfillmentMode::Dropship, DropshipHandling::LastMile);

    $delivery->recordPreparationScan('u-1', dropshipGeo(), dropshipDevice(), new DateTimeImmutable('2026-10-16T08:00:00+00:00'), 'k-prep');

    expect($delivery->status())->toBe(DeliveryStatus::Prepared)
        ->and($delivery->scans())->toHaveCount(1);
});

it('closes a track-only dropship delivery by seller confirmation (dispatch then delivery)', function (): void {
    $delivery = dropshipStart(FulfillmentMode::Dropship, DropshipHandling::TrackOnly);

    $delivery->confirmSellerDispatch(new DateTimeImmutable('2026-10-16T08:00:00+00:00'), 'disp-1', 'SELLER-GUIDE-9');
    expect($delivery->status())->toBe(DeliveryStatus::InTransit);

    $delivery->confirmSellerDelivery(new DateTimeImmutable('2026-10-16T18:00:00+00:00'), 'deliv-1', 'POD-SELLER-9');

    $events = $delivery->releaseEvents();

    expect($delivery->status())->toBe(DeliveryStatus::Delivered)
        ->and($delivery->scans())->toBe([])
        ->and($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(DropshipDispatchConfirmed::class)
        ->and($events[1])->toBeInstanceOf(DropshipDelivered::class);

    /** @var DropshipDispatchConfirmed $dispatched */
    $dispatched = $events[0];
    expect($dispatched->eventName())->toBe('execution.dropship_dispatch_confirmed')
        ->and($dispatched->payload()['handling'])->toBe('track_only')
        ->and($dispatched->payload()['reference'])->toBe('SELLER-GUIDE-9');
});

it('is idempotent on seller confirmations by idempotency key', function (): void {
    $delivery = dropshipStart(FulfillmentMode::Dropship, DropshipHandling::TrackOnly);

    $delivery->confirmSellerDispatch(new DateTimeImmutable('2026-10-16T08:00:00+00:00'), 'disp-1', null);
    $delivery->releaseEvents();

    // Re-confirmación con la misma clave: no re-emite ni cambia el estado.
    $delivery->confirmSellerDispatch(new DateTimeImmutable('2026-10-16T08:00:00+00:00'), 'disp-1', null);

    expect($delivery->status())->toBe(DeliveryStatus::InTransit)
        ->and($delivery->releaseEvents())->toBe([]);
});

it('forbids seller confirmation on an owned delivery', function (): void {
    $delivery = Delivery::start(
        DeliveryId::generate(),
        'a1111111-1111-7111-8111-111111111111',
        'b2222222-2222-7222-8222-222222222222',
    );

    expect(fn () => $delivery->confirmSellerDispatch(new DateTimeImmutable('2026-10-16T08:00:00+00:00'), 'disp-1', null))
        ->toThrow(ExecutionException::class, 'no es dropship por confirmación');
});

it('forbids seller confirmation on a dropship last-mile delivery (it uses own scans)', function (): void {
    $delivery = dropshipStart(FulfillmentMode::Dropship, DropshipHandling::LastMile);

    expect(fn () => $delivery->confirmSellerDelivery(new DateTimeImmutable('2026-10-16T18:00:00+00:00'), 'deliv-1', null))
        ->toThrow(ExecutionException::class, 'no es dropship por confirmación');
});
