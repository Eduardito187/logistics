<?php

declare(strict_types=1);

use Logistics\Inventory\Domain\Event\StockAdjusted;
use Logistics\Inventory\Domain\Event\StockPicked;
use Logistics\Inventory\Domain\Event\StockReceived;
use Logistics\Inventory\Domain\Event\StockReleased;
use Logistics\Inventory\Domain\Event\StockReserved;
use Logistics\Inventory\Domain\Event\StockTransferred;
use Logistics\Inventory\Domain\Exception\InventoryException;
use Logistics\Inventory\Domain\Model\StockLot;
use Logistics\Inventory\Domain\ValueObject\LotId;
use Logistics\Inventory\Domain\ValueObject\Money;
use Logistics\Shared\Domain\ValueObject\Ownership;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Lote propio de Dismac con 100 disponibles.
 */
function inventoryOwnedLot(int $quantity = 100): StockLot
{
    return StockLot::receive(
        LotId::generate(),
        Uuid::generate()->value,
        'DIS-WASH-900',
        Ownership::dismacOwned(),
        $quantity,
        Money::of(180000),
        new DateTimeImmutable('2026-10-16T10:00:00+00:00'),
    );
}

it('recibe stock y graba StockReceived con la cantidad como disponible', function (): void {
    $lot = inventoryOwnedLot(100);

    expect($lot->availableQuantity())->toBe(100)
        ->and($lot->reservedQuantity())->toBe(0)
        ->and($lot->onHandQuantity())->toBe(100)
        ->and($lot->ownership()->isOwned())->toBeTrue();

    $events = $lot->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(StockReceived::class)
        ->and($events[0]->eventName())->toBe('inventory.stock_received')
        ->and($events[0]->quantity)->toBe(100);
});

it('recibe stock en consignación de un seller (FBD) con el dueño correcto', function (): void {
    $seller = Uuid::generate();
    $lot = StockLot::receive(
        LotId::generate(),
        Uuid::generate()->value,
        'BEL-TV-55',
        Ownership::sellerFbd($seller, 'BEL-TV-55'),
        40,
        Money::of(320000),
        new DateTimeImmutable('2026-10-16T10:00:00+00:00'),
        'GR-0001',
    );

    expect($lot->ownership()->isMarketplace())->toBeTrue()
        ->and($lot->ownership()->ownerPartyId?->value)->toBe($seller->value);

    $events = $lot->releaseEvents();
    expect($events[0])->toBeInstanceOf(StockReceived::class)
        ->and($events[0]->ownerPartyId)->toBe($seller->value)
        ->and($events[0]->fulfillmentMode)->toBe('fbd')
        ->and($events[0]->goodsReceiptRef)->toBe('GR-0001');
});

it('rechaza recibir con cantidad no positiva o sin almacén/sku', function (callable $build): void {
    expect($build)->toThrow(InventoryException::class);
})->with([
    'cantidad cero' => [fn () => inventoryOwnedLot(0)],
    'sin almacén' => [fn () => StockLot::receive(LotId::generate(), '  ', 'SKU', Ownership::dismacOwned(), 1, Money::of(0), new DateTimeImmutable)],
    'sin sku' => [fn () => StockLot::receive(LotId::generate(), Uuid::generate()->value, '  ', Ownership::dismacOwned(), 1, Money::of(0), new DateTimeImmutable)],
]);

it('reserva stock moviendo de disponible a reservado sin cambiar el on-hand', function (): void {
    $lot = inventoryOwnedLot(100);
    $lot->releaseEvents();

    $lot->reserve(30, 'ORDER-1');

    expect($lot->availableQuantity())->toBe(70)
        ->and($lot->reservedQuantity())->toBe(30)
        ->and($lot->onHandQuantity())->toBe(100);

    $events = $lot->releaseEvents();
    expect($events[0])->toBeInstanceOf(StockReserved::class)
        ->and($events[0]->quantity)->toBe(30)
        ->and($events[0]->reference)->toBe('ORDER-1');
});

it('no reserva más de lo disponible', function (): void {
    $lot = inventoryOwnedLot(10);
    $lot->releaseEvents();

    expect(fn () => $lot->reserve(11))->toThrow(InventoryException::class, 'disponible');
});

it('libera una reserva devolviéndola a disponible', function (): void {
    $lot = inventoryOwnedLot(100);
    $lot->reserve(40);
    $lot->releaseEvents();

    $lot->release(15);

    expect($lot->availableQuantity())->toBe(75)
        ->and($lot->reservedQuantity())->toBe(25);
    expect($lot->releaseEvents()[0])->toBeInstanceOf(StockReleased::class);
});

it('no libera más de lo reservado', function (): void {
    $lot = inventoryOwnedLot(100);
    $lot->reserve(10);
    $lot->releaseEvents();

    expect(fn () => $lot->release(11))->toThrow(InventoryException::class, 'reservado');
});

it('hace pick de stock reservado bajando el on-hand', function (): void {
    $lot = inventoryOwnedLot(100);
    $lot->reserve(40);
    $lot->releaseEvents();

    $lot->pick(25, 'PICK-1');

    expect($lot->reservedQuantity())->toBe(15)
        ->and($lot->availableQuantity())->toBe(60)
        ->and($lot->onHandQuantity())->toBe(75);
    expect($lot->releaseEvents()[0])->toBeInstanceOf(StockPicked::class);
});

it('no hace pick de más de lo reservado', function (): void {
    $lot = inventoryOwnedLot(100);
    $lot->reserve(5);
    $lot->releaseEvents();

    expect(fn () => $lot->pick(6))->toThrow(InventoryException::class, 'reservado');
});

it('transfiere stock disponible a otro almacén grabando StockTransferred', function (): void {
    $lot = inventoryOwnedLot(100);
    $lot->releaseEvents();
    $destination = Uuid::generate()->value;

    $lot->transfer(20, $destination, 'TR-1');

    expect($lot->availableQuantity())->toBe(80);
    $events = $lot->releaseEvents();
    expect($events[0])->toBeInstanceOf(StockTransferred::class)
        ->and($events[0]->destinationWarehouseId)->toBe($destination)
        ->and($events[0]->quantity)->toBe(20);
});

it('no transfiere al mismo almacén ni más de lo disponible', function (): void {
    $warehouse = Uuid::generate()->value;
    $lot = StockLot::receive(LotId::generate(), $warehouse, 'SKU', Ownership::dismacOwned(), 10, Money::of(0), new DateTimeImmutable);
    $lot->releaseEvents();

    expect(fn () => $lot->transfer(5, $warehouse))->toThrow(InventoryException::class, 'mismo almacén');
    expect(fn () => $lot->transfer(11, Uuid::generate()->value))->toThrow(InventoryException::class, 'disponible');
});

it('ajusta el stock disponible con delta positivo y negativo', function (): void {
    $lot = inventoryOwnedLot(100);
    $lot->releaseEvents();

    $lot->adjust(-5, 'damage');
    expect($lot->availableQuantity())->toBe(95);
    $events = $lot->releaseEvents();
    expect($events[0])->toBeInstanceOf(StockAdjusted::class)
        ->and($events[0]->delta)->toBe(-5)
        ->and($events[0]->reason)->toBe('damage');

    $lot->adjust(10, 'found');
    expect($lot->availableQuantity())->toBe(105);
});

it('no permite un ajuste que deje el stock en negativo, ni ajuste cero/sin motivo', function (): void {
    $lot = inventoryOwnedLot(10);
    $lot->releaseEvents();

    expect(fn () => $lot->adjust(-11, 'x'))->toThrow(InventoryException::class, 'negativo');
    expect(fn () => $lot->adjust(0, 'x'))->toThrow(InventoryException::class);
    expect(fn () => $lot->adjust(1, '  '))->toThrow(InventoryException::class, 'motivo');
});

it('abre el lote destino de una transferencia sin grabar eventos', function (): void {
    $lot = StockLot::openFromTransfer(
        LotId::generate(),
        Uuid::generate()->value,
        'SKU',
        Ownership::dismacOwned(),
        20,
        Money::of(100),
        new DateTimeImmutable,
    );

    expect($lot->availableQuantity())->toBe(20)
        ->and($lot->releaseEvents())->toBe([]);
});
