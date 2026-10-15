<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\UpdateVehicleOdometer;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de fijar el odómetro vigente de un vehículo (en kilómetros). Solo avanza.
 */
final readonly class UpdateVehicleOdometer implements Command
{
    public function __construct(
        public string $vehicleId,
        public int $km,
    ) {}
}
