<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Command\RenameGeofence;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de renombrar una geocerca existente.
 */
final readonly class RenameGeofence implements Command
{
    public function __construct(
        public string $geofenceId,
        public string $name,
    ) {}
}
