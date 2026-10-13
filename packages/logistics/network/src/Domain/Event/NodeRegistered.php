<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se registró un nodo nuevo en la red. Lo consumen coverage (para ubicarlo en la jerarquía
 * geográfica), planning y cualquier integración aguas abajo, vía outbox.
 */
final readonly class NodeRegistered extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        private string $code,
        private string $name,
        private string $type,
        private float $latitude,
        private float $longitude,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'network.node_registered';
    }

    /**
     * @return array{code: string, name: string, type: string, lat: float, lng: float}
     */
    public function payload(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'lat' => $this->latitude,
            'lng' => $this->longitude,
        ];
    }
}
