<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\DefineMaintenancePlan;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de definir un plan de mantenimiento preventivo para un vehículo (por km y/o por días).
 */
final readonly class DefineMaintenancePlan implements Command
{
    public function __construct(
        public string $vehicleId,
        public string $serviceKind,
        public ?int $intervalKm = null,
        public ?int $intervalDays = null,
    ) {}
}
