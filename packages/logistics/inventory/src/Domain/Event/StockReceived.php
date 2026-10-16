<?php

declare(strict_types=1);

namespace Logistics\Inventory\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Ingresó stock nuevo a un lote (recepción FBD de un seller o alta de material propio).
 *
 * Lleva la dimensión de propiedad (modo + dueño) para que el sap-connector pueda distinguir consignación
 * de material propio y para la reportería por dueño aguas abajo.
 */
final readonly class StockReceived extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $warehouseId,
        public string $sku,
        public string $fulfillmentMode,
        public ?string $ownerPartyId,
        public ?string $sellerSkuRef,
        public int $quantity,
        public int $unitCostCents,
        public string $currency,
        public ?string $goodsReceiptRef,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'inventory.stock_received';
    }

    public function payload(): array
    {
        return [
            'warehouse_id' => $this->warehouseId,
            'sku' => $this->sku,
            'fulfillment_mode' => $this->fulfillmentMode,
            'owner_party_id' => $this->ownerPartyId,
            'seller_sku_ref' => $this->sellerSkuRef,
            'quantity' => $this->quantity,
            'unit_cost_cents' => $this->unitCostCents,
            'currency' => $this->currency,
            'goods_receipt_ref' => $this->goodsReceiptRef,
        ];
    }
}
