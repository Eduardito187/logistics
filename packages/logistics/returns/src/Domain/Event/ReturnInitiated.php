<?php

declare(strict_types=1);

namespace Logistics\Returns\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se inició una devolución: se declaró qué vuelve, de quién (seller o retail) y a qué almacén central.
 *
 * Lleva la dimensión de propiedad (owner_party_id + fulfillment_mode) para que aguas abajo se sepa de quién
 * es el producto que retorna, sin que returns dependa de sellers ni de inventory.
 */
final readonly class ReturnInitiated extends BaseDomainEvent
{
    /**
     * @param list<array{sku: string, quantity: int, reason: string}> $lines
     */
    public function __construct(
        string $aggregateId,
        public string $originalReference,
        public ?string $ownerPartyId,
        public string $warehouseId,
        public string $fulfillmentMode,
        public string $kind,
        public array $lines,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'returns.return_initiated';
    }

    public function payload(): array
    {
        return [
            'original_reference' => $this->originalReference,
            'owner_party_id' => $this->ownerPartyId,
            'warehouse_id' => $this->warehouseId,
            'fulfillment_mode' => $this->fulfillmentMode,
            'kind' => $this->kind,
            'lines' => $this->lines,
        ];
    }
}
