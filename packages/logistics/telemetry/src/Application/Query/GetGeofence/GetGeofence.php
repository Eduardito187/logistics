<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\GetGeofence;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide una geocerca por su id.
 */
final readonly class GetGeofence implements Query
{
    public function __construct(
        public string $geofenceId,
    ) {}
}
