<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Command\ActivateGeofence;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de activar una geocerca.
 */
final readonly class ActivateGeofence implements Command
{
    public function __construct(
        public string $geofenceId,
    ) {}
}
