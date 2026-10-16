<?php

declare(strict_types=1);

namespace Logistics\Inventory\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se ajustó el stock disponible de un lote (merma, hallazgo, corrección de conteo). El delta puede ser
 * positivo o negativo; el motivo queda registrado para auditoría.
 */
final readonly class StockAdjusted extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $warehouseId,
        public string $sku,
        public string $fulfillmentMode,
        public ?string $ownerPartyId,
        public int $delta,
        public string $reason,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'inventory.stock_adjusted';
    }

    public function payload(): array
    {
        return [
            'warehouse_id' => $this->warehouseId,
            'sku' => $this->sku,
            'fulfillment_mode' => $this->fulfillmentMode,
            'owner_party_id' => $this->ownerPartyId,
            'delta' => $this->delta,
            'reason' => $this->reason,
        ];
    }
}
