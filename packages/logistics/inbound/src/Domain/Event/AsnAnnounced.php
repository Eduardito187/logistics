<?php

declare(strict_types=1);

namespace Logistics\Inbound\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Un seller anunció un ASN: declaró qué va a enviar a un almacén central, sin cita todavía.
 */
final readonly class AsnAnnounced extends BaseDomainEvent
{
    /**
     * @param list<array{sku: string, expected_quantity: int}> $lines
     */
    public function __construct(
        string $aggregateId,
        public string $sellerId,
        public string $warehouseId,
        public array $lines,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'inbound.asn_announced';
    }

    public function payload(): array
    {
        return [
            'seller_id' => $this->sellerId,
            'warehouse_id' => $this->warehouseId,
            'lines' => $this->lines,
        ];
    }
}
