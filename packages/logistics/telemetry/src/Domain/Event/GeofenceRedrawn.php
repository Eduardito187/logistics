<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se redibujó el polígono de una geocerca.
 */
final readonly class GeofenceRedrawn extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public int $pointCount,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'telemetry.geofence_redrawn';
    }

    public function payload(): array
    {
        return [
            'point_count' => $this->pointCount,
        ];
    }
}
