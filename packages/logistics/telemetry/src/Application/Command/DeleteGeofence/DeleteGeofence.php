<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Command\DeleteGeofence;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de eliminar una geocerca.
 */
final readonly class DeleteGeofence implements Command
{
    public function __construct(
        public string $geofenceId,
    ) {}
}
