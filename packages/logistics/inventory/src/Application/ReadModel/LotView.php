<?php

declare(strict_types=1);

namespace Logistics\Inventory\Application\ReadModel;

/**
 * Proyección de lectura de un lote de stock para listados y API. Primitivos: no expone objetos de dominio.
 */
final readonly class LotView
{
    public function __construct(
        public string $id,
        public string $warehouseId,
        public string $sku,
        public string $fulfillmentMode,
        public ?string $ownerPartyId,
        public ?string $sellerSkuRef,
        public int $availableQuantity,
        public int $reservedQuantity,
        public int $onHand,
        public int $unitCostCents,
        public string $currency,
        public string $receivedAt,
        public ?string $goodsReceiptRef,
    ) {}

    /**
     * @param array{id: string, warehouse_id: string, sku: string, fulfillment_mode: string, owner_party_id: ?string, seller_sku_ref: ?string, available_quantity: int, reserved_quantity: int, on_hand: int, unit_cost_cents: int, currency: string, received_at: string, goods_receipt_ref: ?string} $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            $row['id'],
            $row['warehouse_id'],
            $row['sku'],
            $row['fulfillment_mode'],
            $row['owner_party_id'],
            $row['seller_sku_ref'],
            $row['available_quantity'],
            $row['reserved_quantity'],
            $row['on_hand'],
            $row['unit_cost_cents'],
            $row['currency'],
            $row['received_at'],
            $row['goods_receipt_ref'],
        );
    }

    /**
     * @return array{id: string, warehouse_id: string, sku: string, fulfillment_mode: string, owner_party_id: ?string, seller_sku_ref: ?string, available_quantity: int, reserved_quantity: int, on_hand: int, unit_cost: array{cents: int, currency: string}, received_at: string, goods_receipt_ref: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouseId,
            'sku' => $this->sku,
            'fulfillment_mode' => $this->fulfillmentMode,
            'owner_party_id' => $this->ownerPartyId,
            'seller_sku_ref' => $this->sellerSkuRef,
            'available_quantity' => $this->availableQuantity,
            'reserved_quantity' => $this->reservedQuantity,
            'on_hand' => $this->onHand,
            'unit_cost' => ['cents' => $this->unitCostCents, 'currency' => $this->currency],
            'received_at' => $this->receivedAt,
            'goods_receipt_ref' => $this->goodsReceiptRef,
        ];
    }
}
