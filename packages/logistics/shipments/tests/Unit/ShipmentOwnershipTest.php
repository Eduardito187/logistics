<?php

declare(strict_types=1);

use Logistics\Shared\Domain\ValueObject\FulfillmentMode;
use Logistics\Shared\Domain\ValueObject\Ownership;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Domain\Model\Shipment;
use Logistics\Shipments\Domain\Model\ShipmentLine;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\AddressConfidence;
use Logistics\Shipments\Domain\ValueObject\Customer;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\ProductName;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;
use Logistics\Shipments\Domain\ValueObject\ShipmentLineId;
use Logistics\Shipments\Domain\ValueObject\Sku;
use Logistics\Shipments\Domain\ValueObject\SourceReference;

/**
 * Dimensión de propiedad (ADR 017) cableada en los agregados del contexto: por defecto todo es Dismac
 * (retail, `OWNED`) y los bultos heredan la propiedad del envío; un envío de un seller la propaga.
 *
 * Arma una línea con la cantidad dada.
 */
function ownershipLine(string $sku, string $name, int $quantity): ShipmentLine
{
    return ShipmentLine::create(ShipmentLineId::generate(), Sku::of($sku), ProductName::of($name), $quantity);
}

/**
 * Recibe un envío de ejemplo, con la propiedad dada (o la retail por defecto).
 *
 * @param list<ShipmentLine>|null $lines
 */
function receiveShipmentWithOwnership(?Ownership $ownership = null, ?array $lines = null): Shipment
{
    return Shipment::receive(
        ShipmentId::generate(),
        OrderSource::Sap,
        SourceReference::of(OrderSource::Sap, 'DM-260012345'),
        Customer::of('Juana Perez'),
        Address::of('Av. Siempre Viva 742'),
        AddressConfidence::of(80),
        $lines ?? [ownershipLine('LG-GT32', 'Heladera LG', 2)],
        null,
        $ownership,
    );
}

it('defaults a shipment and its units to Dismac owned (retail)', function (): void {
    $shipment = receiveShipmentWithOwnership();

    expect($shipment->ownership()->isOwned())->toBeTrue()
        ->and($shipment->ownership()->mode)->toBe(FulfillmentMode::Owned)
        ->and($shipment->ownership()->ownerPartyId)->toBeNull();

    foreach ($shipment->physicalUnits() as $unit) {
        expect($unit->ownership()->isOwned())->toBeTrue();
    }
});

it('propagates seller ownership from the shipment to its physical units', function (): void {
    $seller = Uuid::generate();
    $ownership = Ownership::sellerFbd($seller, 'SELLER-SKU-1');

    $shipment = receiveShipmentWithOwnership($ownership, [ownershipLine('LG-GT32', 'Heladera LG', 3)]);

    expect($shipment->ownership()->isMarketplace())->toBeTrue()
        ->and($shipment->ownership()->mode)->toBe(FulfillmentMode::Fbd)
        ->and($shipment->ownership()->ownerPartyId?->equals($seller))->toBeTrue()
        ->and($shipment->ownership()->sellerSkuRef)->toBe('SELLER-SKU-1')
        ->and($shipment->physicalUnits())->toHaveCount(3);

    foreach ($shipment->physicalUnits() as $unit) {
        expect($unit->ownership()->equals($ownership))->toBeTrue();
    }
});

it('reconstitutes owned ownership from legacy primitives (mode owned, owner null)', function (): void {
    $ownership = Ownership::fromPrimitives('owned', null, null);

    expect($ownership->isOwned())->toBeTrue()
        ->and($ownership->ownerPartyId)->toBeNull()
        ->and($ownership->equals(Ownership::dismacOwned()))->toBeTrue();
});

it('reconstitutes seller ownership from primitives', function (): void {
    $seller = Uuid::generate();
    $ownership = Ownership::fromPrimitives('dropship', $seller->value, 'SKU-9');

    expect($ownership->mode)->toBe(FulfillmentMode::Dropship)
        ->and($ownership->ownerPartyId?->equals($seller))->toBeTrue()
        ->and($ownership->sellerSkuRef)->toBe('SKU-9');
});

it('enforces the ownership invariant: owned cannot carry an owner party id', function (): void {
    expect(fn () => Ownership::fromPrimitives('owned', Uuid::generate()->value, null))
        ->toThrow(InvalidArgumentException::class);
});

it('enforces the ownership invariant: marketplace requires an owner party id', function (): void {
    expect(fn () => Ownership::fromPrimitives('fbd', null, null))
        ->toThrow(InvalidArgumentException::class);
});
