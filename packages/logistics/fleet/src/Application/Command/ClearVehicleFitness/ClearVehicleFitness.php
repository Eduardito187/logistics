<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\ClearVehicleFitness;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de un supervisor de habilitar de nuevo un vehículo NO APTO tras repararlo.
 *
 * El autor (id + etiqueta legible) lo resuelve la capa de presentación desde la sesión; el id va como
 * texto porque la cuenta admin tiene id bigint (no uuid).
 */
final readonly class ClearVehicleFitness implements Command
{
    public function __construct(
        public string $vehicleId,
        public string $note,
        public ?string $clearedById = null,
        public string $clearedByLabel = 'System',
    ) {}
}
