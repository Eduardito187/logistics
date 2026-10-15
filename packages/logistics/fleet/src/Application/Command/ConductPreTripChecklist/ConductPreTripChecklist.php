<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\ConductPreTripChecklist;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de registrar un checklist pre-salida ejecutado por un chofer sobre un vehículo.
 *
 * Los ítems llegan como mapa nombre => verificado (bool): así la entrada no se acopla al VO del
 * dominio, que el handler construye y valida.
 */
final readonly class ConductPreTripChecklist implements Command
{
    /**
     * @param array<string, bool> $items
     */
    public function __construct(
        public string $vehicleId,
        public string $driverId,
        public array $items,
        public string $performedOn,
        public ?string $notes = null,
    ) {}
}
