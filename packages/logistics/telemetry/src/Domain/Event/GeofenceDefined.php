<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se definió una geocerca nueva.
 */
final readonly class GeofenceDefined extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $name,
        public string $type,
        public ?string $nodeRef,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'telemetry.geofence_defined';
    }

    public function payload(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'node_ref' => $this->nodeRef,
        ];
    }
}
