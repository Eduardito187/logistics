<?php

declare(strict_types=1);

use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Execution\Domain\ValueObject\DeliveryId;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\ProofOfDelivery;
use Logistics\Shared\Domain\ValueObject\FulfillmentMode;

// Flujo vigente (sin escaneos de preparación/carga en el app): asignar el vehículo abre la entrega LISTA
// para el POD directo. Antes se abría en Pending y `markDelivered` fallaba con "transición inválida a
// Delivered desde PENDING".

const DOD_OPEN_AT = '2026-11-12T14:00:00+00:00';
const DOD_POD_AT = '2026-11-12T15:00:00+00:00';

function podFor(): ProofOfDelivery
{
    return ProofOfDelivery::of('FIRMA_DIGITAL', null, new DateTimeImmutable(DOD_POD_AT));
}

function deviceFor(): DeviceContext
{
    return DeviceContext::of('device-1', 'user-7');
}

it('abre la entrega en LOADED al asignar el vehículo y permite el POD directo', function (): void {
    $delivery = Delivery::open(
        DeliveryId::generate(),
        'ship-1',
        'veh-1',
        FulfillmentMode::Owned,
        new DateTimeImmutable(DOD_OPEN_AT),
    );

    expect($delivery->status())->toBe(DeliveryStatus::Loaded);
    $delivery->releaseEvents();

    $delivery->recordProofOfDelivery('U-1', podFor(), null, deviceFor(), new DateTimeImmutable(DOD_POD_AT), 'idem-1');

    expect($delivery->status())->toBe(DeliveryStatus::Delivered);
});

it('permite entregar una entrega todavía en PENDING (red de seguridad para las ya abiertas)', function (): void {
    $delivery = Delivery::reconstitute(
        DeliveryId::generate(),
        'ship-2',
        'veh-1',
        DeliveryStatus::Pending,
        [],
        null,
        null,
    );

    $delivery->recordProofOfDelivery('U-1', podFor(), null, deviceFor(), new DateTimeImmutable(DOD_POD_AT), 'idem-2');

    expect($delivery->status())->toBe(DeliveryStatus::Delivered);
});
