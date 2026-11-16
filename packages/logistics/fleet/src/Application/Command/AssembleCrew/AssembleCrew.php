<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\AssembleCrew;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de armar una tripulación (chofer + ayudantes + vehículo) para una fecha.
 *
 * Regla del día: un chofer tiene un solo camión por día y un camión una sola tripulación por día. Armar
 * para un chofer que ya tiene tripulación ese día la REEMPLAZA (no suma otra).
 *
 * - `helperIds` null = el pedido no dice nada de ayudantes: se conservan los de la tripulación anterior.
 *   Una lista (aunque esté vacía) manda sobre los anteriores.
 * - `shiftStartsAt` y `shiftEndsAt` son opcionales (texto de fecha-hora, p. ej. ISO8601); cuando vienen
 *   ambos, el handler fija el turno y valida la asignación contra las reglas de turnos y descansos. Si no
 *   vienen, se conserva el turno de la tripulación anterior.
 * - `replace` confirma quitarle el camión al chofer que ya lo tiene ese día; sin esto, ese caso se rechaza.
 * - `replacesCrewId` es la tripulación que se está editando (PUT): queda sin efecto al armar la nueva.
 */
final readonly class AssembleCrew implements Command
{
    /**
     * @param list<string>|null $helperIds
     */
    public function __construct(
        public string $driverId,
        public ?array $helperIds,
        public string $vehicleId,
        public string $assembledFor,
        public ?string $shiftStartsAt = null,
        public ?string $shiftEndsAt = null,
        public bool $replace = false,
        public ?string $replacesCrewId = null,
    ) {}
}
