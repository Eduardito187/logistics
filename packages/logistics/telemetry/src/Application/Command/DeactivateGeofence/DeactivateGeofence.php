<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Command\DeactivateGeofence;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de desactivar una geocerca.
 */
final readonly class DeactivateGeofence implements Command
{
    public function __construct(
        public string $geofenceId,
    ) {}
}
