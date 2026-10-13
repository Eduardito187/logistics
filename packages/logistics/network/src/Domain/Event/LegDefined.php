<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se definió un tramo/corredor entre dos nodos. Lo consume planning (para armar rutas lógicas) y
 * cualquier integración aguas abajo, vía outbox.
 */
final readonly class LegDefined extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        private string $fromNodeId,
        private string $toNodeId,
        private string $corridorType,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'network.leg_defined';
    }

    /**
     * @return array{from_node_id: string, to_node_id: string, corridor_type: string}
     */
    public function payload(): array
    {
        return [
            'from_node_id' => $this->fromNodeId,
            'to_node_id' => $this->toNodeId,
            'corridor_type' => $this->corridorType,
        ];
    }
}
