<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se registró la recepción de un bulto en un nodo logístico. El nodo se referencia por id string
 * (no se acopla al contexto network).
 */
final readonly class NodeReceptionRecorded extends BaseDomainEvent
{
    public function __construct(
        string $deliveryId,
        public string $shipmentId,
        public string $nodeId,
        public string $unitId,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($deliveryId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.node_reception_recorded';
    }

    public function payload(): array
    {
        return [
            'delivery_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'node_id' => $this->nodeId,
            'unit_id' => $this->unitId,
        ];
    }
}
