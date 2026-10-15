<?php

declare(strict_types=1);

use Logistics\Execution\Domain\Event\ShipmentUnloaded;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Execution\Domain\ValueObject\DeliveryId;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Execution\Domain\ValueObject\ScanType;

/**
 * Arma una entrega ya cargada (preparada + cargada), lista para la descarga guiada.
 */
function loadedDelivery(): Delivery
{
    $delivery = Delivery::start(
        DeliveryId::generate(),
        'a1111111-1111-7111-8111-111111111111',
        'b2222222-2222-7222-8222-222222222222',
    );
    $geo = GeoPoint::of(-16.5, -68.15);
    $device = DeviceContext::of('device-01', 'user-07');
    $delivery->recordPreparationScan('u-1', $geo, $device, new DateTimeImmutable('2026-10-13T08:00:00+00:00'), 'k-prep');
    $delivery->recordLoadScan('u-1', $geo, $device, new DateTimeImmutable('2026-10-13T08:30:00+00:00'), 'k-load');
    $delivery->releaseEvents();

    return $delivery;
}

it('records a guided unload at a stop and moves the delivery to InTransit', function (): void {
    $delivery = loadedDelivery();
    $geo = GeoPoint::of(-16.51, -68.14);
    $device = DeviceContext::of('device-01', 'user-07');

    $delivery->recordUnloadScan('n-7', 'u-1', $geo, $device, new DateTimeImmutable('2026-10-13T10:00:00+00:00'), 'k-unload');

    $events = $delivery->releaseEvents();

    expect($delivery->status())->toBe(DeliveryStatus::InTransit)
        ->and($delivery->scans())->toHaveCount(3)
        ->and($delivery->scans()[2]->type)->toBe(ScanType::Unload)
        ->and($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ShipmentUnloaded::class);

    /** @var ShipmentUnloaded $unloaded */
    $unloaded = $events[0];
    expect($unloaded->eventName())->toBe('execution.shipment_unloaded')
        ->and($unloaded->payload()['node_id'])->toBe('n-7');
});

it('is idempotent on unload: a repeated scan neither duplicates nor re-emits', function (): void {
    $delivery = loadedDelivery();
    $geo = GeoPoint::of(-16.51, -68.14);
    $device = DeviceContext::of('device-01', 'user-07');

    $delivery->recordUnloadScan('n-7', 'u-1', $geo, $device, new DateTimeImmutable('2026-10-13T10:00:00+00:00'), 'same');
    $delivery->releaseEvents();
    $delivery->recordUnloadScan('n-7', 'u-1', $geo, $device, new DateTimeImmutable('2026-10-13T10:00:00+00:00'), 'same');

    expect($delivery->scans())->toHaveCount(3)
        ->and($delivery->releaseEvents())->toBe([]);
});

it('rejects a guided unload without a stop id', function (): void {
    $delivery = loadedDelivery();
    $geo = GeoPoint::of(-16.51, -68.14);
    $device = DeviceContext::of('device-01', 'user-07');

    expect(fn () => $delivery->recordUnloadScan('  ', 'u-1', $geo, $device, new DateTimeImmutable('2026-10-13T10:00:00+00:00'), 'k'))
        ->toThrow(ExecutionException::class, 'parada');
});
