<?php

declare(strict_types=1);

use Logistics\Shipments\Domain\Event\ShipmentPlanned;
use Logistics\Shipments\Domain\Event\ShipmentReceived;
use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\Model\Shipment;
use Logistics\Shipments\Domain\Model\ShipmentLine;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\AddressConfidence;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\ProductName;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;
use Logistics\Shipments\Domain\ValueObject\ShipmentLineId;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;
use Logistics\Shipments\Domain\ValueObject\Sku;
use Logistics\Shipments\Domain\ValueObject\SourceReference;

/**
 * Arma una línea con la cantidad dada.
 */
function shipmentLine(string $sku, string $name, int $quantity): ShipmentLine
{
    return ShipmentLine::create(ShipmentLineId::generate(), Sku::of($sku), ProductName::of($name), $quantity);
}

/**
 * Recibe un envío de ejemplo con las líneas dadas (o una por defecto).
 *
 * @param list<ShipmentLine>|null $lines
 */
function receiveShipment(?array $lines = null): Shipment
{
    return Shipment::receive(
        ShipmentId::generate(),
        OrderSource::Sap,
        SourceReference::of(OrderSource::Sap, 'DM-260012345'),
        'Juana Perez',
        Address::of('Av. Siempre Viva 742', 'Portón azul'),
        AddressConfidence::of(80),
        $lines ?? [shipmentLine('LG-GT32', 'Heladera LG', 1)],
    );
}

it('receives a valid shipment in Received status', function (): void {
    $shipment = receiveShipment();

    expect($shipment->status())->toBe(ShipmentStatus::Received)
        ->and($shipment->customerName())->toBe('Juana Perez')
        ->and($shipment->source())->toBe(OrderSource::Sap)
        ->and($shipment->addressConfidence()->score)->toBe(80);
});

it('generates one physical unit per product unit, numbered N of M per line', function (): void {
    $line = shipmentLine('LG-GT32', 'Heladera LG', 3);
    $shipment = receiveShipment([$line]);

    $units = $shipment->physicalUnits();

    expect($units)->toHaveCount(3)
        ->and($units[0]->unit()->toCanonical())->toBe('1/3')
        ->and($units[1]->unit()->toCanonical())->toBe('2/3')
        ->and($units[2]->unit()->toCanonical())->toBe('3/3')
        ->and($units[0]->piece()->toCanonical())->toBe('1/1')
        ->and($units[0]->lineId()->equals($line->id()))->toBeTrue();
});

it('numbers units independently across lines', function (): void {
    $shipment = receiveShipment([
        shipmentLine('A', 'Producto A', 2),
        shipmentLine('B', 'Producto B', 1),
    ]);

    expect($shipment->physicalUnits())->toHaveCount(3);
});

it('records a ShipmentReceived event with counts', function (): void {
    $shipment = receiveShipment([shipmentLine('LG-GT32', 'Heladera LG', 2)]);

    $events = $shipment->releaseEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ShipmentReceived::class);

    /** @var ShipmentReceived $event */
    $event = $events[0];
    expect($event->eventName())->toBe('shipments.shipment_received')
        ->and($event->lineCount)->toBe(1)
        ->and($event->unitCount)->toBe(2)
        ->and($event->payload()['external_id'])->toBe('DM-260012345');
});

it('transitions to Planned and records the event', function (): void {
    $shipment = receiveShipment();
    $shipment->releaseEvents();

    $shipment->markPlanned();

    $events = $shipment->releaseEvents();
    expect($shipment->status())->toBe(ShipmentStatus::Planned)
        ->and($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ShipmentPlanned::class);
});

it('rejects an invalid transition to Planned', function (): void {
    $shipment = receiveShipment();
    $shipment->markPlanned();

    expect(fn () => $shipment->markPlanned())
        ->toThrow(ShipmentsException::class, 'transición inválida');
});

it('requires at least one line', function (): void {
    expect(fn () => receiveShipment([]))
        ->toThrow(ShipmentsException::class, 'al menos una línea');
});

it('rejects a source that does not match its reference', function (): void {
    expect(fn () => Shipment::receive(
        ShipmentId::generate(),
        OrderSource::Magento,
        SourceReference::of(OrderSource::Sap, 'DM-1'),
        'Juana Perez',
        Address::of('Calle 1'),
        AddressConfidence::of(50),
        [shipmentLine('A', 'Producto A', 1)],
    ))->toThrow(ShipmentsException::class, 'no coincide');
});

it('reconstitutes without recording events, enabling idempotent reload', function (): void {
    $original = receiveShipment([shipmentLine('A', 'Producto A', 2)]);
    $original->releaseEvents();

    $reloaded = Shipment::reconstitute(
        $original->id(),
        $original->source(),
        $original->sourceReference(),
        $original->customerName(),
        $original->address(),
        $original->addressConfidence(),
        $original->status(),
        $original->lines(),
        $original->physicalUnits(),
    );

    // Reconstituir no es un hecho nuevo: no graba eventos (la idempotencia de ingreso la garantiza
    // la referencia de origen, no un segundo evento).
    expect($reloaded->releaseEvents())->toBe([])
        ->and($reloaded->id()->equals($original->id()))->toBeTrue()
        ->and($reloaded->sourceReference()->equals($original->sourceReference()))->toBeTrue()
        ->and($reloaded->physicalUnits())->toHaveCount(2);
});
