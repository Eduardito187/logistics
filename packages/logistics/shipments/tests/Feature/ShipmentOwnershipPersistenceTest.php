<?php

declare(strict_types=1);

use Logistics\Shared\Domain\ValueObject\FulfillmentMode;
use Logistics\Shared\Domain\ValueObject\Ownership;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\Model\Shipment;
use Logistics\Shipments\Domain\Model\ShipmentLine;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\AddressConfidence;
use Logistics\Shipments\Domain\ValueObject\Customer;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\ProductName;
use Logistics\Shipments\Domain\ValueObject\ShipmentLineId;
use Logistics\Shipments\Domain\ValueObject\Sku;
use Logistics\Shipments\Domain\ValueObject\SourceReference;

/**
 * Persistencia de la dimensión de propiedad (ADR 017): el envío y sus bultos guardan y releen la
 * propiedad. El flujo retail (`OWNED`) persiste idéntico; un envío de seller persiste y se relee con su
 * dueño. Contra PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    $this->repository = app(ShipmentRepository::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Arma y guarda un envío con la propiedad dada (o la retail por defecto) y lo devuelve ya persistido.
 */
function persistShipmentWithOwnership(ShipmentRepository $repository, ?Ownership $ownership, string $externalId): Shipment
{
    $shipment = Shipment::receive(
        $repository->nextIdentity(),
        OrderSource::Sap,
        SourceReference::of(OrderSource::Sap, $externalId),
        Customer::of('Juana Perez'),
        Address::of('Av. Siempre Viva 742'),
        AddressConfidence::of(80),
        [ShipmentLine::create(ShipmentLineId::generate(), Sku::of('LG-GT32'), ProductName::of('Heladera LG'), 2)],
        null,
        $ownership,
    );

    $repository->save($shipment);

    return $shipment;
}

it('persists and reloads a retail (owned) shipment unchanged', function (): void {
    $saved = persistShipmentWithOwnership($this->repository, null, 'DM-OWNED-1');

    $reloaded = $this->repository->findById($saved->id());

    expect($reloaded)->not->toBeNull()
        ->and($reloaded->ownership()->isOwned())->toBeTrue()
        ->and($reloaded->ownership()->mode)->toBe(FulfillmentMode::Owned)
        ->and($reloaded->ownership()->ownerPartyId)->toBeNull()
        ->and($reloaded->physicalUnits())->toHaveCount(2);

    foreach ($reloaded->physicalUnits() as $unit) {
        expect($unit->ownership()->isOwned())->toBeTrue();
    }
});

it('persists and reloads a seller (FBD) shipment with its owner and units', function (): void {
    $seller = Uuid::generate();
    $ownership = Ownership::sellerFbd($seller, 'SELLER-SKU-1');

    $saved = persistShipmentWithOwnership($this->repository, $ownership, 'DM-FBD-1');

    $reloaded = $this->repository->findById($saved->id());

    expect($reloaded)->not->toBeNull()
        ->and($reloaded->ownership()->isMarketplace())->toBeTrue()
        ->and($reloaded->ownership()->mode)->toBe(FulfillmentMode::Fbd)
        ->and($reloaded->ownership()->ownerPartyId?->equals($seller))->toBeTrue()
        ->and($reloaded->ownership()->sellerSkuRef)->toBe('SELLER-SKU-1')
        ->and($reloaded->physicalUnits())->toHaveCount(2);

    foreach ($reloaded->physicalUnits() as $unit) {
        expect($unit->ownership()->equals($ownership))->toBeTrue();
    }
});
