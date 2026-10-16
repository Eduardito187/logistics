<?php

declare(strict_types=1);

namespace Logistics\Inventory\Infrastructure\Persistence\Eloquent\Mapper;

use Logistics\Inventory\Domain\Model\StockLot;
use Logistics\Inventory\Domain\ValueObject\LotId;
use Logistics\Inventory\Domain\ValueObject\Money;
use Logistics\Inventory\Infrastructure\Persistence\Eloquent\Model\InventoryLotRecord;
use Logistics\Shared\Domain\ValueObject\Ownership;

/**
 * Traduce entre el agregado StockLot (dominio) y su fila Eloquent. Único punto que conoce ambos lados.
 */
final class StockLotMapper
{
    public function toDomain(InventoryLotRecord $record): StockLot
    {
        return StockLot::reconstitute(
            LotId::fromString($record->id),
            $record->warehouse_id,
            $record->sku,
            Ownership::fromPrimitives($record->fulfillment_mode, $record->owner_party_id, $record->seller_sku_ref),
            $record->available_quantity,
            $record->reserved_quantity,
            Money::of($record->unit_cost_cents, $record->currency),
            // El atributo es Carbon (cast datetime): convertir con toDateTimeImmutable (ver gotcha 22).
            $record->received_at->toDateTimeImmutable(),
            $record->goods_receipt_ref,
        );
    }

    /**
     * Fila de la tabla `inventory_lots`.
     *
     * @return array{id: string, warehouse_id: string, sku: string, fulfillment_mode: string, owner_party_id: ?string, seller_sku_ref: ?string, available_quantity: int, reserved_quantity: int, unit_cost_cents: int, currency: string, received_at: string, goods_receipt_ref: ?string}
     */
    public function toRow(StockLot $lot): array
    {
        $ownership = $lot->ownership();

        return [
            'id' => $lot->id()->toString(),
            'warehouse_id' => $lot->warehouseId(),
            'sku' => $lot->sku(),
            'fulfillment_mode' => $ownership->mode->value,
            'owner_party_id' => $ownership->ownerPartyId?->value,
            'seller_sku_ref' => $ownership->sellerSkuRef,
            'available_quantity' => $lot->availableQuantity(),
            'reserved_quantity' => $lot->reservedQuantity(),
            'unit_cost_cents' => $lot->unitCost()->cents,
            'currency' => $lot->unitCost()->currency,
            'received_at' => $lot->receivedAt()->format('Y-m-d H:i:s'),
            'goods_receipt_ref' => $lot->goodsReceiptRef(),
        ];
    }
}
