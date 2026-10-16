<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Inventory\Application\Command\AdjustStock\AdjustStock;
use Logistics\Inventory\Application\Command\PickStock\PickStock;
use Logistics\Inventory\Application\Command\ReceiveStock\ReceiveStock;
use Logistics\Inventory\Application\Command\ReleaseStock\ReleaseStock;
use Logistics\Inventory\Application\Command\ReserveStock\ReserveStock;
use Logistics\Inventory\Application\Command\TransferStock\TransferStock;
use Logistics\Inventory\Application\Query\GetAvailableToPromise\GetAvailableToPromise;
use Logistics\Inventory\Application\Query\GetStockOnHand\GetStockOnHand;
use Logistics\Inventory\Application\Query\ListLots\ListLots;
use Logistics\Inventory\Application\ReadModel\AvailableToPromiseView;
use Logistics\Inventory\Application\ReadModel\LotView;
use Logistics\Inventory\Application\ReadModel\StockOnHandView;
use Logistics\Inventory\Infrastructure\Persistence\Eloquent\Model\InventoryLotRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Stack completo del contexto Inventory contra logistics_test: command → handler → repositorio + ledger +
 * outbox, y las consultas CQRS (on-hand por dueño, lotes, ATP). Skip sin PostgreSQL.
 */
beforeEach(function (): void {
    $this->commands = app(CommandBus::class);
    $this->queries = app(QueryBus::class);
    $this->warehouse = Uuid::generate()->value;
    $this->seller = Uuid::generate()->value;
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Recibe un lote por el bus y devuelve su id (leído de la proyección por sku+almacén).
 */
function inventoryReceiveViaBus(CommandBus $bus, string $warehouse, string $sku, int $qty, ?string $seller = null): string
{
    $bus->dispatch(new ReceiveStock(
        warehouseId: $warehouse,
        sku: $sku,
        quantity: $qty,
        fulfillmentMode: $seller !== null ? 'fbd' : 'owned',
        ownerPartyId: $seller,
        unitCostCents: 180000,
    ));

    /** @var InventoryLotRecord $record */
    $record = InventoryLotRecord::query()
        ->where('warehouse_id', $warehouse)
        ->where('sku', $sku)
        ->firstOrFail();

    return (string) $record->getKey();
}

it('recibe stock, crea el lote y encola StockReceived en el outbox', function (): void {
    $id = inventoryReceiveViaBus($this->commands, $this->warehouse, 'DIS-WASH-900', 100);

    $this->assertDatabaseHas('inventory_lots', [
        'id' => $id,
        'sku' => 'DIS-WASH-900',
        'fulfillment_mode' => 'owned',
        'available_quantity' => 100,
        'reserved_quantity' => 0,
    ]);
    $this->assertDatabaseHas('inventory_movements', ['lot_id' => $id, 'movement_type' => 'received']);

    expect(DB::table('outbox_messages')->where('event_name', 'inventory.stock_received')->count())
        ->toBeGreaterThanOrEqual(1);
});

it('reserva, libera y hace pick ajustando disponible/reservado y el on-hand', function (): void {
    $id = inventoryReceiveViaBus($this->commands, $this->warehouse, 'DIS-WASH-900', 100);

    $this->commands->dispatch(new ReserveStock(lotId: $id, quantity: 40, reference: 'ORDER-1'));
    $this->commands->dispatch(new ReleaseStock(lotId: $id, quantity: 10));
    $this->commands->dispatch(new PickStock(lotId: $id, quantity: 20, reference: 'PICK-1'));

    // available: 100 -40 +10 = 70 ; reserved: 40 -10 -20 = 10 ; on-hand: 80
    $this->assertDatabaseHas('inventory_lots', [
        'id' => $id,
        'available_quantity' => 70,
        'reserved_quantity' => 10,
    ]);
});

it('ajusta el stock de un lote', function (): void {
    $id = inventoryReceiveViaBus($this->commands, $this->warehouse, 'DIS-MICRO-20', 300);

    $this->commands->dispatch(new AdjustStock(lotId: $id, delta: -5, reason: 'damage'));

    $this->assertDatabaseHas('inventory_lots', ['id' => $id, 'available_quantity' => 295]);
    $this->assertDatabaseHas('inventory_movements', ['lot_id' => $id, 'movement_type' => 'adjusted']);
});

it('transfiere stock a otro almacén conservando el dueño', function (): void {
    $id = inventoryReceiveViaBus($this->commands, $this->warehouse, 'BEL-TV-55', 40, $this->seller);
    $destination = Uuid::generate()->value;

    $this->commands->dispatch(new TransferStock(lotId: $id, quantity: 15, destinationWarehouseId: $destination));

    $this->assertDatabaseHas('inventory_lots', ['id' => $id, 'available_quantity' => 25]);
    // El lote destino conserva el dueño (consignación del mismo seller).
    $this->assertDatabaseHas('inventory_lots', [
        'warehouse_id' => $destination,
        'sku' => 'BEL-TV-55',
        'fulfillment_mode' => 'fbd',
        'owner_party_id' => $this->seller,
        'available_quantity' => 15,
    ]);
});

it('consulta stock on-hand agregado por dueño', function (): void {
    inventoryReceiveViaBus($this->commands, $this->warehouse, 'MIX-1', 50);
    inventoryReceiveViaBus($this->commands, $this->warehouse, 'MIX-1', 30, $this->seller);

    /** @var list<StockOnHandView> $rows */
    $rows = $this->queries->ask(new GetStockOnHand(warehouseId: $this->warehouse, sku: 'MIX-1'));
    $byMode = [];
    foreach ($rows as $row) {
        $byMode[$row->fulfillmentMode] = $row->onHand;
    }

    expect($byMode['owned'] ?? 0)->toBe(50)
        ->and($byMode['fbd'] ?? 0)->toBe(30);
});

it('consulta el available-to-promise y lista lotes con filtro por dueño', function (): void {
    $id = inventoryReceiveViaBus($this->commands, $this->warehouse, 'ATP-1', 80, $this->seller);
    $this->commands->dispatch(new ReserveStock(lotId: $id, quantity: 30));

    /** @var AvailableToPromiseView $atp */
    $atp = $this->queries->ask(new GetAvailableToPromise(
        warehouseId: $this->warehouse,
        sku: 'ATP-1',
        ownerPartyId: $this->seller,
    ));
    expect($atp->availableToPromise)->toBe(50);

    /** @var list<LotView> $lots */
    $lots = $this->queries->ask(new ListLots(warehouseId: $this->warehouse, sku: 'ATP-1', ownerPartyId: $this->seller));
    expect($lots)->toHaveCount(1)
        ->and($lots[0]->ownerPartyId)->toBe($this->seller)
        ->and($lots[0]->onHand)->toBe(80);
});
