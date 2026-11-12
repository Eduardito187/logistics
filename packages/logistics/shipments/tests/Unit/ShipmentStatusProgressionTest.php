<?php

declare(strict_types=1);

use Logistics\Shipments\Domain\Event\ShipmentExecutionStarted;
use Logistics\Shipments\Domain\Event\ShipmentMarkedDelivered;
use Logistics\Shipments\Domain\Event\ShipmentPlanned;
use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\Model\Shipment;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\AddressConfidence;
use Logistics\Shipments\Domain\ValueObject\Customer;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;
use Logistics\Shipments\Domain\ValueObject\SourceReference;

/**
 * Avance monótono del estado del envío (Shipment::progressTo) y regla del nodo de retiro por sufijo
 * "#n:{uuid}" (SourceReference::pickupNodeId). PHP puro, sin base.
 */
function sspDomainShipment(ShipmentStatus $status): Shipment
{
    return Shipment::reconstitute(
        ShipmentId::generate(),
        OrderSource::Manual,
        SourceReference::of(OrderSource::Manual, 'ORD-'.bin2hex(random_bytes(3))),
        Customer::of('Cliente Demo'),
        Address::of('Av. Siempre Viva 123'),
        AddressConfidence::of(80),
        $status,
        [],
        [],
    );
}

it('avanza RECEIVED → PLANNED → IN_EXECUTION → DELIVERED grabando un hecho por paso', function (): void {
    $shipment = sspDomainShipment(ShipmentStatus::Received);

    expect($shipment->progressTo(ShipmentStatus::Planned))->toBeTrue()
        ->and($shipment->status())->toBe(ShipmentStatus::Planned)
        ->and($shipment->progressTo(ShipmentStatus::InExecution))->toBeTrue()
        ->and($shipment->status())->toBe(ShipmentStatus::InExecution)
        ->and($shipment->progressTo(ShipmentStatus::Delivered))->toBeTrue()
        ->and($shipment->status())->toBe(ShipmentStatus::Delivered);

    $events = $shipment->releaseEvents();
    expect($events)->toHaveCount(3)
        ->and($events[0])->toBeInstanceOf(ShipmentPlanned::class)
        ->and($events[1])->toBeInstanceOf(ShipmentExecutionStarted::class)
        ->and($events[2])->toBeInstanceOf(ShipmentMarkedDelivered::class);
});

it('salta pasos intermedios cuando el hecho llega sin plan (cargado desde RECEIVED)', function (): void {
    $shipment = sspDomainShipment(ShipmentStatus::Received);

    expect($shipment->progressTo(ShipmentStatus::InExecution))->toBeTrue()
        ->and($shipment->status())->toBe(ShipmentStatus::InExecution);
});

it('nunca retrocede y es idempotente: el mismo destino o uno anterior es un no-op sin hechos', function (): void {
    $shipment = sspDomainShipment(ShipmentStatus::InExecution);

    expect($shipment->progressTo(ShipmentStatus::Planned))->toBeFalse()
        ->and($shipment->progressTo(ShipmentStatus::InExecution))->toBeFalse()
        ->and($shipment->status())->toBe(ShipmentStatus::InExecution)
        ->and($shipment->releaseEvents())->toBe([]);
});

it('no toca un envío cerrado (entregado, fallido o anulado)', function (ShipmentStatus $closed): void {
    $shipment = sspDomainShipment($closed);

    expect($shipment->progressTo(ShipmentStatus::Planned))->toBeFalse()
        ->and($shipment->progressTo(ShipmentStatus::InExecution))->toBeFalse()
        ->and($shipment->progressTo(ShipmentStatus::Delivered))->toBeFalse()
        ->and($shipment->status())->toBe($closed);
})->with([
    'entregado' => [ShipmentStatus::Delivered],
    'fallido' => [ShipmentStatus::Failed],
    'anulado' => [ShipmentStatus::Cancelled],
]);

it('rechaza destinos que no son de avance (recibido, fallido, anulado)', function (ShipmentStatus $target): void {
    expect(fn (): bool => sspDomainShipment(ShipmentStatus::Received)->progressTo($target))
        ->toThrow(ShipmentsException::class);
})->with([
    'recibido' => [ShipmentStatus::Received],
    'fallido' => [ShipmentStatus::Failed],
    'anulado' => [ShipmentStatus::Cancelled],
]);

it('normaliza el estado sin importar mayúsculas', function (): void {
    expect(ShipmentStatus::tryFromAnyCase('planned'))->toBe(ShipmentStatus::Planned)
        ->and(ShipmentStatus::tryFromAnyCase(' In_Execution '))->toBe(ShipmentStatus::InExecution)
        ->and(ShipmentStatus::tryFromAnyCase('nope'))->toBeNull();
});

it('lee el nodo de retiro del sufijo "#n:{uuid}" del id externo', function (): void {
    $node = '0199a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b';

    expect(SourceReference::of(OrderSource::Commerce, 'ORD-1#n:'.$node)->pickupNodeId())->toBe($node)
        ->and(SourceReference::of(OrderSource::Commerce, 'ORD-1#n:'.strtoupper($node))->pickupNodeId())->toBe($node)
        ->and(SourceReference::of(OrderSource::Commerce, 'ORD-1')->pickupNodeId())->toBeNull()
        ->and(SourceReference::of(OrderSource::Commerce, 'ORD-1#n:no-es-uuid')->pickupNodeId())->toBeNull();
});
