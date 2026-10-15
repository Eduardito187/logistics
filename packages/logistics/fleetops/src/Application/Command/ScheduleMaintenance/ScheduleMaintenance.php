<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\ScheduleMaintenance;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de agendar una orden de mantenimiento para un vehículo.
 *
 * El odómetro llega como km (entero) opcional y la fecha como texto ISO 8601; el handler los convierte
 * a los VO del dominio para no acoplar la entrada a ellos.
 */
final readonly class ScheduleMaintenance implements Command
{
    public function __construct(
        public string $vehicleId,
        public string $type,
        public ?int $odometerKm,
        public ?string $workshop,
        public string $scheduledAt,
        public ?string $note = null,
    ) {}
}
