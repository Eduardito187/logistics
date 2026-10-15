<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Command\RedrawGeofence;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de redibujar el polígono de una geocerca existente.
 */
final readonly class RedrawGeofence implements Command
{
    /**
     * @param list<array{lat: float, lng: float}> $polygon
     */
    public function __construct(
        public string $geofenceId,
        public array $polygon,
    ) {}
}
