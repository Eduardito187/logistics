<?php

declare(strict_types=1);

namespace Logistics\SellerPortal\Application\ReadModel;

/**
 * Vista de un lote de stock on-hand del seller (consignación). Forma primitiva.
 */
final readonly class SellerStockView
{
    public function __construct(
        public string $id,
        public string $ownerPartyId,
        public string $warehouseId,
        public string $sku,
        public string $fulfillmentMode,
        public ?string $sellerSkuRef,
        public int $availableQuantity,
        public int $reservedQuantity,
        public int $unitCostCents,
        public string $currency,
        public ?string $receivedAt,
        public ?string $goodsReceiptRef,
    ) {}

    /**
     * Construye la vista desde una fila cruda de `inventory_lots` (stdClass de DB::table).
     */
    public static function fromRow(object $row): self
    {
        return new self(
            id: self::str($row, 'id'),
            ownerPartyId: self::str($row, 'owner_party_id'),
            warehouseId: self::str($row, 'warehouse_id'),
            sku: self::str($row, 'sku'),
            fulfillmentMode: self::str($row, 'fulfillment_mode'),
            sellerSkuRef: self::nullableStr($row, 'seller_sku_ref'),
            availableQuantity: self::int($row, 'available_quantity'),
            reservedQuantity: self::int($row, 'reserved_quantity'),
            unitCostCents: self::int($row, 'unit_cost_cents'),
            currency: self::str($row, 'currency'),
            receivedAt: self::nullableStr($row, 'received_at'),
            goodsReceiptRef: self::nullableStr($row, 'goods_receipt_ref'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'owner_party_id' => $this->ownerPartyId,
            'warehouse_id' => $this->warehouseId,
            'sku' => $this->sku,
            'fulfillment_mode' => $this->fulfillmentMode,
            'seller_sku_ref' => $this->sellerSkuRef,
            'available_quantity' => $this->availableQuantity,
            'reserved_quantity' => $this->reservedQuantity,
            'unit_cost_cents' => $this->unitCostCents,
            'currency' => $this->currency,
            'received_at' => $this->receivedAt,
            'goods_receipt_ref' => $this->goodsReceiptRef,
        ];
    }

    private static function str(object $row, string $field): string
    {
        $value = $row->{$field} ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullableStr(object $row, string $field): ?string
    {
        $value = $row->{$field} ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    private static function int(object $row, string $field): int
    {
        $value = $row->{$field} ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
