<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\AssembleCrew;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de armar una tripulación (chofer + ayudantes + vehículo) para una fecha.
 */
final readonly class AssembleCrew implements Command
{
    /**
     * @param list<string> $helperIds
     */
    public function __construct(
        public string $driverId,
        public array $helperIds,
        public string $vehicleId,
        public string $assembledFor,
    ) {}
}
