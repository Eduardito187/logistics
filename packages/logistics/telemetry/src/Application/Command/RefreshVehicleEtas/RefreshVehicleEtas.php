<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Command\RefreshVehicleEtas;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de recalcular y guardar la ETA dinámica de las paradas restantes de un vehículo desde su última
 * posición (con duraciones viales OSRM si hay backend). La despacha el comando programado, nunca un request.
 */
final readonly class RefreshVehicleEtas implements Command
{
    public function __construct(
        public string $vehicleId,
    ) {}
}
