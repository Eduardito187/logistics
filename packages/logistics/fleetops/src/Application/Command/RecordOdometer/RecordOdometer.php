<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\RecordOdometer;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de registrar una lectura de odómetro de un vehículo. La fuente llega como string del enum
 * OdometerSource ('manual'|'telemetry'|'refuel'|'maintenance'|'departure') y la fecha en ISO 8601.
 */
final readonly class RecordOdometer implements Command
{
    public function __construct(
        public string $vehicleId,
        public int $km,
        public string $source,
        public string $readAt,
        public ?string $note = null,
    ) {}
}
