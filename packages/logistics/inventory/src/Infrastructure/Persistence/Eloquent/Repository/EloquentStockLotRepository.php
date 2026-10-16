<?php

declare(strict_types=1);

namespace Logistics\Inventory\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\Inventory\Domain\Contract\StockLotRepository;
use Logistics\Inventory\Domain\Model\StockLot;
use Logistics\Inventory\Domain\ValueObject\LotId;
use Logistics\Inventory\Domain\ValueObject\StockMovement;
use Logistics\Inventory\Infrastructure\Persistence\Eloquent\Mapper\StockLotMapper;
use Logistics\Inventory\Infrastructure\Persistence\Eloquent\Model\InventoryLotRecord;
use Logistics\Inventory\Infrastructure\Persistence\Eloquent\Model\InventoryMovementRecord;

/**
 * Implementación Eloquent del repositorio de lotes (lado de escritura). `save` es un upsert por id; el
 * ledger de movimientos es append-only.
 */
final readonly class EloquentStockLotRepository implements StockLotRepository
{
    public function __construct(
        private StockLotMapper $mapper = new StockLotMapper,
    ) {}

    public function nextIdentity(): LotId
    {
        return LotId::generate();
    }

    public function save(StockLot $lot): void
    {
        $row = $this->mapper->toRow($lot);
        InventoryLotRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(LotId $id): ?StockLot
    {
        $record = InventoryLotRecord::query()->find($id->toString());

        return $record instanceof InventoryLotRecord ? $this->mapper->toDomain($record) : null;
    }

    public function appendMovement(StockMovement $movement): void
    {
        InventoryMovementRecord::query()->create([
            'id' => LotId::generate()->toString(),
            'lot_id' => $movement->lotId->toString(),
            'warehouse_id' => $movement->warehouseId,
            'sku' => $movement->sku,
            'fulfillment_mode' => $movement->ownership->mode->value,
            'owner_party_id' => $movement->ownership->ownerPartyId?->value,
            'movement_type' => $movement->type->value,
            'quantity' => $movement->quantity,
            'reference' => $movement->reference,
            'related_warehouse_id' => $movement->relatedWarehouseId,
            'occurred_at' => $movement->occurredAt->format('Y-m-d H:i:s'),
        ]);
    }
}
