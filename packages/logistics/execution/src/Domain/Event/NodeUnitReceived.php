<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se escaneó la recepción de un bulto en el nodo destino (hecho inmutable con hora, GPS, usuario y
 * dispositivo). Tracking lo consume para mostrar el avance de la recepción bulto a bulto.
 *
 * Nodo referenciado por id string: no se acopla al contexto network.
 */
final readonly class NodeUnitReceived extends BaseDomainEvent
{
    public function __construct(
        string $nodeReceptionId,
        public string $shipmentId,
        public string $destinationNodeId,
        public string $unitId,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($nodeReceptionId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.node_unit_received';
    }

    public function payload(): array
    {
        return [
            'node_reception_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'destination_node_id' => $this->destinationNodeId,
            'unit_id' => $this->unitId,
        ];
    }
}
