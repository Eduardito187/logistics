<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se desactivó una geocerca (deja de evaluarse en la detección de mal uso).
 */
final readonly class GeofenceDeactivated extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'telemetry.geofence_deactivated';
    }

    public function payload(): array
    {
        return [];
    }
}
