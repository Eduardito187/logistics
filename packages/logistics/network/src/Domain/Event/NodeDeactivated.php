<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se dio de baja un nodo. Planning debería dejar de proponerlo como origen o destino; network solo
 * registra el hecho.
 */
final readonly class NodeDeactivated extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        private string $code,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'network.node_deactivated';
    }

    /**
     * @return array{code: string}
     */
    public function payload(): array
    {
        return ['code' => $this->code];
    }
}
