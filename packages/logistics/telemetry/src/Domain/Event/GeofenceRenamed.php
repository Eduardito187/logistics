<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se renombró una geocerca.
 */
final readonly class GeofenceRenamed extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $name,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'telemetry.geofence_renamed';
    }

    public function payload(): array
    {
        return [
            'name' => $this->name,
        ];
    }
}
