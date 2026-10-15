<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se activó una geocerca (vuelve a evaluarse en la detección de mal uso).
 */
final readonly class GeofenceActivated extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'telemetry.geofence_activated';
    }

    public function payload(): array
    {
        return [];
    }
}
