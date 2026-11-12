<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se ubicó (o reubicó) en estante un producto recibido en el nodo. Hecho inmutable para auditoría y para
 * que tracking / reportes sepan dónde está físicamente el producto dentro del nodo.
 *
 * Nodo referenciado por id string: no se acopla al contexto network.
 */
final readonly class NodeUnitShelved extends BaseDomainEvent
{
    public function __construct(
        string $nodeReceptionId,
        public string $shipmentId,
        public string $nodeId,
        public string $unitId,
        public string $location,
        public ?string $previousLocation,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($nodeReceptionId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.node_unit_shelved';
    }

    public function payload(): array
    {
        return [
            'node_reception_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'node_id' => $this->nodeId,
            'unit_id' => $this->unitId,
            'location' => $this->location,
            'previous_location' => $this->previousLocation,
        ];
    }
}
