<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\AssembleCrew;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de armar una tripulación (chofer + ayudantes + vehículo) para una fecha.
 *
 * `shiftStartsAt` y `shiftEndsAt` son opcionales (texto de fecha-hora, p. ej. ISO8601); cuando vienen
 * ambos, el handler fija el turno y valida la asignación contra las reglas de turnos y descansos.
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
        public ?string $shiftStartsAt = null,
        public ?string $shiftEndsAt = null,
    ) {}
}
