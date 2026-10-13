<?php

declare(strict_types=1);

use Logistics\Execution\Domain\Event\DeliveryFailed;
use Logistics\Execution\Domain\Event\NodeReceptionRecorded;
use Logistics\Execution\Domain\Event\ShipmentDelivered;
use Logistics\Execution\Domain\Event\ShipmentLoaded;
use Logistics\Execution\Domain\Event\ShipmentPrepared;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Execution\Domain\ValueObject\DeliveryId;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\FailureReason;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Execution\Domain\ValueObject\ProofOfDelivery;

/**
 * Arma una entrega nueva de ejemplo.
 */
function newDelivery(): Delivery
{
    return Delivery::start(DeliveryId::generate(), 'a1111111-1111-7111-8111-111111111111', 'b2222222-2222-7222-8222-222222222222');
}

function geo(): GeoPoint
{
    return GeoPoint::of(-16.5, -68.15);
}

function device(): DeviceContext
{
    return DeviceContext::of('device-01', 'user-07');
}

function at(string $time): DateTimeImmutable
{
    return new DateTimeImmutable($time);
}

it('starts a delivery in Pending with no scans', function (): void {
    $delivery = newDelivery();

    expect($delivery->status())->toBe(DeliveryStatus::Pending)
        ->and($delivery->scans())->toBe([])
        ->and($delivery->releaseEvents())->toBe([]);
});

it('runs the prepare -> load -> proof-of-delivery sequence recording an event per phase', function (): void {
    $delivery = newDelivery();

    $delivery->recordPreparationScan('u-1', geo(), device(), at('2026-10-13T08:00:00+00:00'), 'k-prep');
    $delivery->recordLoadScan('u-1', geo(), device(), at('2026-10-13T09:00:00+00:00'), 'k-load');
    $delivery->recordProofOfDelivery(
        'u-1',
        ProofOfDelivery::of('QR-OK-123', 'https://cdn/foto.jpg', at('2026-10-13T12:00:00+00:00')),
        geo(),
        device(),
        at('2026-10-13T12:00:00+00:00'),
        'k-pod',
    );

    $events = $delivery->releaseEvents();

    expect($delivery->status())->toBe(DeliveryStatus::Delivered)
        ->and($delivery->scans())->toHaveCount(3)
        ->and($events)->toHaveCount(3)
        ->and($events[0])->toBeInstanceOf(ShipmentPrepared::class)
        ->and($events[1])->toBeInstanceOf(ShipmentLoaded::class)
        ->and($events[2])->toBeInstanceOf(ShipmentDelivered::class);

    /** @var ShipmentDelivered $delivered */
    $delivered = $events[2];
    expect($delivered->eventName())->toBe('execution.shipment_delivered')
        ->and($delivered->payload()['confirmation'])->toBe('QR-OK-123')
        ->and($delivery->proofOfDelivery()?->confirmation)->toBe('QR-OK-123');
});

it('allows several preparation scans but records ShipmentPrepared only on the first', function (): void {
    $delivery = newDelivery();

    $delivery->recordPreparationScan('u-1', geo(), device(), at('2026-10-13T08:00:00+00:00'), 'k-1');
    $delivery->recordPreparationScan('u-2', geo(), device(), at('2026-10-13T08:01:00+00:00'), 'k-2');

    $events = $delivery->releaseEvents();

    expect($delivery->scans())->toHaveCount(2)
        ->and($delivery->status())->toBe(DeliveryStatus::Prepared)
        ->and($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ShipmentPrepared::class);
});

it('is idempotent by idempotency key: a repeated scan neither duplicates nor re-emits', function (): void {
    $delivery = newDelivery();

    $delivery->recordPreparationScan('u-1', geo(), device(), at('2026-10-13T08:00:00+00:00'), 'same-key');
    $delivery->releaseEvents();

    // Reenvío offline del mismo escaneo: misma clave.
    $delivery->recordPreparationScan('u-1', geo(), device(), at('2026-10-13T08:00:00+00:00'), 'same-key');

    expect($delivery->scans())->toHaveCount(1)
        ->and($delivery->releaseEvents())->toBe([]);
});

it('records a failed delivery with its typed reason', function (): void {
    $delivery = newDelivery();
    $delivery->recordPreparationScan('u-1', geo(), device(), at('2026-10-13T08:00:00+00:00'), 'k-prep');
    $delivery->recordLoadScan('u-1', geo(), device(), at('2026-10-13T09:00:00+00:00'), 'k-load');
    $delivery->releaseEvents();

    $delivery->recordFailed(FailureReason::CustomerAbsent, 'u-1', geo(), device(), at('2026-10-13T12:00:00+00:00'), 'k-fail');

    $events = $delivery->releaseEvents();

    expect($delivery->status())->toBe(DeliveryStatus::Failed)
        ->and($delivery->failureReason())->toBe(FailureReason::CustomerAbsent)
        ->and($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(DeliveryFailed::class);

    /** @var DeliveryFailed $failed */
    $failed = $events[0];
    expect($failed->payload()['reason'])->toBe('CUSTOMER_ABSENT');
});

it('records a node reception and moves the delivery to InTransit', function (): void {
    $delivery = newDelivery();
    $delivery->recordPreparationScan('u-1', geo(), device(), at('2026-10-13T08:00:00+00:00'), 'k-prep');
    $delivery->recordLoadScan('u-1', geo(), device(), at('2026-10-13T09:00:00+00:00'), 'k-load');
    $delivery->releaseEvents();

    $delivery->recordNodeReception('n-5', 'u-1', geo(), device(), at('2026-10-13T10:00:00+00:00'), 'k-node');

    $events = $delivery->releaseEvents();

    expect($delivery->status())->toBe(DeliveryStatus::InTransit)
        ->and($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(NodeReceptionRecorded::class);

    /** @var NodeReceptionRecorded $node */
    $node = $events[0];
    expect($node->payload()['node_id'])->toBe('n-5');
});

it('rejects an invalid transition: proof of delivery before loading', function (): void {
    $delivery = newDelivery();
    $delivery->recordPreparationScan('u-1', geo(), device(), at('2026-10-13T08:00:00+00:00'), 'k-prep');

    expect(fn () => $delivery->recordProofOfDelivery(
        'u-1',
        ProofOfDelivery::of('QR', null, at('2026-10-13T12:00:00+00:00')),
        geo(),
        device(),
        at('2026-10-13T12:00:00+00:00'),
        'k-pod',
    ))->toThrow(ExecutionException::class, 'transición inválida');
});

it('rejects loading a delivery that was never prepared', function (): void {
    $delivery = newDelivery();

    expect(fn () => $delivery->recordLoadScan('u-1', geo(), device(), at('2026-10-13T09:00:00+00:00'), 'k-load'))
        ->toThrow(ExecutionException::class, 'transición inválida');
});

it('reconstitutes without recording events', function (): void {
    $delivery = newDelivery();
    $delivery->recordPreparationScan('u-1', geo(), device(), at('2026-10-13T08:00:00+00:00'), 'k-prep');
    $delivery->releaseEvents();

    $reloaded = Delivery::reconstitute(
        $delivery->id(),
        $delivery->shipmentId(),
        $delivery->vehicleId(),
        $delivery->status(),
        $delivery->scans(),
        $delivery->proofOfDelivery(),
        $delivery->failureReason(),
    );

    expect($reloaded->releaseEvents())->toBe([])
        ->and($reloaded->status())->toBe(DeliveryStatus::Prepared)
        ->and($reloaded->scans())->toHaveCount(1)
        ->and($reloaded->id()->equals($delivery->id()))->toBeTrue();
});
