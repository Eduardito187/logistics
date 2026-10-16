<?php

declare(strict_types=1);

namespace Logistics\Inventory\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se liberó una reserva de un lote: la cantidad volvió de reservada a disponible.
 *
 * Es el contraparte natural de StockReserved. Todo cambio de estado del lote graba su hecho (ningún
 * cambio de estado queda silencioso), aun cuando el roadmap lista explícitamente los otros cinco eventos.
 */
final readonly class StockReleased extends BaseDomainEvent
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
        return 'inventory.stock_released';
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
