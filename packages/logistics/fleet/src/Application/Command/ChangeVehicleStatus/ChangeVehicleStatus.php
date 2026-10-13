<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\ChangeVehicleStatus;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de cambiar el estado operativo de un vehículo (p. ej. enviarlo a mantenimiento).
 */
final readonly class ChangeVehicleStatus implements Command
{
    public function __construct(
        public string $vehicleId,
        public string $status,
    ) {}
}
