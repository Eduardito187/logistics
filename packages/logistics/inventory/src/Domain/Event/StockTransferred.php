<?php

declare(strict_types=1);

namespace Logistics\Inventory\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se transfirió stock de un lote hacia otro almacén (salida del lote origen). La propiedad del stock no
 * cambia con la transferencia: el lote destino conserva el mismo dueño (segregación lógica).
 */
final readonly class StockTransferred extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $warehouseId,
        public string $destinationWarehouseId,
        public string $sku,
        public string $fulfillmentMode,
        public ?string $ownerPartyId,
        public int $quantity,
        public ?string $reference,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'inventory.stock_transferred';
    }

    public function payload(): array
    {
        return [
            'warehouse_id' => $this->warehouseId,
            'destination_warehouse_id' => $this->destinationWarehouseId,
            'sku' => $this->sku,
            'fulfillment_mode' => $this->fulfillmentMode,
            'owner_party_id' => $this->ownerPartyId,
            'quantity' => $this->quantity,
            'reference' => $this->reference,
        ];
    }
}
