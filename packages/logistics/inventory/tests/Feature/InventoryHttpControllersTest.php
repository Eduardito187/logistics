<?php

declare(strict_types=1);

use Logistics\Inventory\Application\Command\ReceiveStock\ReceiveStock;
use Logistics\Inventory\Application\Command\ReserveStock\ReserveStock;
use Logistics\Inventory\Infrastructure\Persistence\Eloquent\Model\InventoryLotRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Cobertura de los controllers HTTP del contexto Inventory por sus rutas reales de la API v1 (puerta
 * `integrations`, solo lectura). Patrón F del brief: se omite el middleware de puerta para ejercitar el
 * contrato de los controllers (envelope `data`, filtros por dueño/modo) y la cadena CQRS detrás
 * (consultas, ReadModels y read store). Se siembra por el CommandBus real. Corre contra logistics_test;
 * skip sin PostgreSQL.
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    $this->withoutMiddleware();
    $this->commands = app(CommandBus::class);
    $this->warehouse = Uuid::generate()->value;
    $this->seller = Uuid::generate()->value;
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Siembra un lote por el bus de comandos (owned si $seller es null, FBD en consignación si no).
 */
function inventoryHttpReceive(CommandBus $bus, string $warehouse, string $sku, int $qty, ?string $seller = null): void
{
    $bus->dispatch(new ReceiveStock(
        warehouseId: $warehouse,
        sku: $sku,
        quantity: $qty,
        fulfillmentMode: $seller !== null ? 'fbd' : 'owned',
        ownerPartyId: $seller,
        unitCostCents: 180000,
    ));
}

it('devuelve el stock on-hand agregado por dueño', function (): void {
    inventoryHttpReceive($this->commands, $this->warehouse, 'MIX-1', 50);
    inventoryHttpReceive($this->commands, $this->warehouse, 'MIX-1', 30, $this->seller);

    $response = $this->getJson('/api/v1/integrations/inventory/on-hand?'.http_build_query([
        'warehouse_id' => $this->warehouse,
        'sku' => 'MIX-1',
    ]))->assertOk()->assertJsonStructure(['data']);

    $byMode = [];
    foreach ($response->json('data') as $row) {
        $byMode[$row['fulfillment_mode']] = $row['on_hand'];
    }
    expect($byMode['owned'] ?? 0)->toBe(50)
        ->and($byMode['fbd'] ?? 0)->toBe(30);
});

it('filtra el on-hand por modo de fulfillment', function (): void {
    inventoryHttpReceive($this->commands, $this->warehouse, 'MIX-2', 50);
    inventoryHttpReceive($this->commands, $this->warehouse, 'MIX-2', 30, $this->seller);

    $this->getJson('/api/v1/integrations/inventory/on-hand?'.http_build_query([
        'warehouse_id' => $this->warehouse,
        'sku' => 'MIX-2',
        'fulfillment_mode' => 'fbd',
    ]))->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.fulfillment_mode', 'fbd')
        ->assertJsonPath('data.0.on_hand', 30);
});

it('devuelve el available-to-promise por dueño descontando lo reservado', function (): void {
    inventoryHttpReceive($this->commands, $this->warehouse, 'ATP-1', 80, $this->seller);
    /** @var InventoryLotRecord $lot */
    $lot = InventoryLotRecord::query()->where('warehouse_id', $this->warehouse)->where('sku', 'ATP-1')->firstOrFail();
    $this->commands->dispatch(new ReserveStock(lotId: (string) $lot->getKey(), quantity: 30));

    $this->getJson('/api/v1/integrations/inventory/available-to-promise?'.http_build_query([
        'warehouse_id' => $this->warehouse,
        'sku' => 'ATP-1',
        'owner_party_id' => $this->seller,
    ]))->assertOk()
        ->assertJsonPath('data.available_to_promise', 50)
        ->assertJsonPath('data.owner_party_id', $this->seller);
});

it('lista lotes paginados con filtro por dueño y modo', function (): void {
    inventoryHttpReceive($this->commands, $this->warehouse, 'LOT-1', 80, $this->seller);
    inventoryHttpReceive($this->commands, $this->warehouse, 'LOT-2', 10);

    $this->getJson('/api/v1/integrations/inventory/lots?'.http_build_query([
        'warehouse_id' => $this->warehouse,
        'owner_party_id' => $this->seller,
        'fulfillment_mode' => 'fbd',
    ]))->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.sku', 'LOT-1')
        ->assertJsonPath('data.0.owner_party_id', $this->seller)
        ->assertJsonStructure(['data' => [['id', 'unit_cost' => ['cents', 'currency']]], 'meta']);
});

it('lista todos los lotes del almacén sin filtros opcionales', function (): void {
    inventoryHttpReceive($this->commands, $this->warehouse, 'ALL-1', 5);
    inventoryHttpReceive($this->commands, $this->warehouse, 'ALL-2', 7);

    $this->getJson('/api/v1/integrations/inventory/lots?'.http_build_query([
        'warehouse_id' => $this->warehouse,
    ]))->assertOk()
        ->assertJsonCount(2, 'data');
});
