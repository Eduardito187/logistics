<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\DisbandCrew;

use Logistics\Shared\Application\Command\Command;

/**
 * Quita una tripulación: el chofer deja de tener ese vehículo asignado para esa fecha.
 */
final readonly class DisbandCrew implements Command
{
    public function __construct(
        public string $crewId,
    ) {}
}
