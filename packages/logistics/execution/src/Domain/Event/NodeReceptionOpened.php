<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se abrió la recepción de un envío en un nodo destino: se anunció qué envío llega, por qué corredor y
 * cuántos bultos se esperan. Tracking lo usa para mostrar el envío "en recepción" en el nodo.
 *
 * Nodos referenciados por id string: no se acopla al contexto network.
 */
final readonly class NodeReceptionOpened extends BaseDomainEvent
{
    public function __construct(
        string $nodeReceptionId,
        public string $shipmentId,
        public string $originNodeId,
        public string $destinationNodeId,
        public string $corridor,
        public int $expectedCount,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($nodeReceptionId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.node_reception_opened';
    }

    public function payload(): array
    {
        return [
            'node_reception_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'origin_node_id' => $this->originNodeId,
            'destination_node_id' => $this->destinationNodeId,
            'corridor' => $this->corridor,
            'expected_count' => $this->expectedCount,
        ];
    }
}
