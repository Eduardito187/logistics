<?php

declare(strict_types=1);

namespace Logistics\Inventory\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se reservó stock de un lote: la cantidad pasó de disponible a reservada (el on-hand no cambia).
 */
final readonly class StockReserved extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $warehouseId,
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
        return 'inventory.stock_reserved';
    }

    public function payload(): array
    {
        return [
            'warehouse_id' => $this->warehouseId,
            'sku' => $this->sku,
            'fulfillment_mode' => $this->fulfillmentMode,
            'owner_party_id' => $this->ownerPartyId,
            'quantity' => $this->quantity,
            'reference' => $this->reference,
        ];
    }
}
