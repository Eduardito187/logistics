<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\CloseDeparture;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de cerrar una salida al retornar el vehículo: odómetro de regreso y fecha (ISO 8601).
 */
final readonly class CloseDeparture implements Command
{
    public function __construct(
        public string $departureId,
        public int $odometerInKm,
        public string $returnedAt,
    ) {}
}
