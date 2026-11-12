<?php

declare(strict_types=1);

use Logistics\Execution\Domain\Event\ShipmentDelivered;
use Logistics\Execution\Domain\Event\ShipmentDeparted;
use Logistics\Execution\Domain\Event\ShipmentLoaded;
use Logistics\Execution\Domain\Event\ShipmentPrepared;
use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Execution\Domain\Model\ScanEvent;
use Logistics\Execution\Domain\ValueObject\DeliveryId;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Execution\Domain\ValueObject\ProofOfDelivery;
use Logistics\Execution\Domain\ValueObject\ScanType;
use Logistics\Shared\Domain\ValueObject\FulfillmentMode;

/**
 * Flujo único de ejecución (decisión de negocio): todo pedido se despacha igual sin importar la propiedad.
 * OWNED, FBD y DROPSHIP recorren preparación → carga → salida → entrega con escaneos propios; el
 * `FulfillmentMode` queda registrado en la entrega solo como dato (reportes/liquidaciones).
 * Helpers con prefijo uniformFlow* para no colisionar con los globales de DeliveryTest (Pest un solo proceso).
 */
function uniformFlowStart(FulfillmentMode $mode): Delivery
{
    return Delivery::start(
        DeliveryId::generate(),
        'a1111111-1111-7111-8111-111111111111',
        'b2222222-2222-7222-8222-222222222222',
        $mode,
    );
}

function uniformFlowGeo(): GeoPoint
{
    return GeoPoint::of(-16.5, -68.15);
}

function uniformFlowDevice(): DeviceContext
{
    return DeviceContext::of('device-01', 'user-07');
}

/**
 * Corre el flujo completo con escaneos propios y devuelve los nombres de los eventos grabados.
 *
 * @return list<string>
 */
function uniformFlowRun(Delivery $delivery): array
{
    $delivery->recordPreparationScan('u-1', uniformFlowGeo(), uniformFlowDevice(), new DateTimeImmutable('2026-10-16T08:00:00+00:00'), 'k-prep');
    $delivery->recordLoadScan('u-1', uniformFlowGeo(), uniformFlowDevice(), new DateTimeImmutable('2026-10-16T09:00:00+00:00'), 'k-load');
    $delivery->markDeparted(new DateTimeImmutable('2026-10-16T10:00:00+00:00'));
    $delivery->recordProofOfDelivery(
        'u-1',
        ProofOfDelivery::of('QR-OK-1', null, new DateTimeImmutable('2026-10-16T12:00:00+00:00')),
        uniformFlowGeo(),
        uniformFlowDevice(),
        new DateTimeImmutable('2026-10-16T12:00:00+00:00'),
        'k-pod',
    );

    return array_map(static fn (object $event): string => $event::class, $delivery->releaseEvents());
}

it('starts an owned delivery by default', function (): void {
    $delivery = Delivery::start(
        DeliveryId::generate(),
        'a1111111-1111-7111-8111-111111111111',
        'b2222222-2222-7222-8222-222222222222',
    );

    expect($delivery->fulfillmentMode())->toBe(FulfillmentMode::Owned);
});

it('runs a dropship delivery through preparation, load, departure and POD with own scans like an owned one', function (): void {
    $owned = uniformFlowStart(FulfillmentMode::Owned);
    $dropship = uniformFlowStart(FulfillmentMode::Dropship);

    $ownedEvents = uniformFlowRun($owned);
    $dropshipEvents = uniformFlowRun($dropship);

    $expected = [
        ShipmentPrepared::class,
        ShipmentLoaded::class,
        ShipmentDeparted::class,
        ShipmentDelivered::class,
    ];

    expect($dropshipEvents)->toBe($expected)
        ->and($ownedEvents)->toBe($expected)
        ->and($dropship->status())->toBe(DeliveryStatus::Delivered)
        ->and($dropship->fulfillmentMode())->toBe(FulfillmentMode::Dropship)
        ->and(array_map(static fn (ScanEvent $scan): ScanType => $scan->type, $dropship->scans()))
        ->toBe([ScanType::Preparation, ScanType::Load, ScanType::ProofOfDelivery]);
});

it('runs an FBD delivery through the same own-scan flow', function (): void {
    $delivery = uniformFlowStart(FulfillmentMode::Fbd);

    expect(uniformFlowRun($delivery))->toHaveCount(4)
        ->and($delivery->status())->toBe(DeliveryStatus::Delivered)
        ->and($delivery->fulfillmentMode())->toBe(FulfillmentMode::Fbd);
});

it('keeps the fulfillment mode when reconstituting a dropship delivery', function (): void {
    $delivery = uniformFlowStart(FulfillmentMode::Dropship);
    $delivery->recordPreparationScan('u-1', uniformFlowGeo(), uniformFlowDevice(), new DateTimeImmutable('2026-10-16T08:00:00+00:00'), 'k-prep');
    $delivery->releaseEvents();

    $reloaded = Delivery::reconstitute(
        $delivery->id(),
        $delivery->shipmentId(),
        $delivery->vehicleId(),
        $delivery->status(),
        $delivery->scans(),
        $delivery->proofOfDelivery(),
        $delivery->failureReason(),
        $delivery->fulfillmentMode(),
    );

    // Tras reconstituir, sigue el mismo flujo propio: la carga se acepta sin ninguna rama especial.
    $reloaded->recordLoadScan('u-1', uniformFlowGeo(), uniformFlowDevice(), new DateTimeImmutable('2026-10-16T09:00:00+00:00'), 'k-load');

    expect($reloaded->fulfillmentMode())->toBe(FulfillmentMode::Dropship)
        ->and($reloaded->status())->toBe(DeliveryStatus::Loaded);
});
