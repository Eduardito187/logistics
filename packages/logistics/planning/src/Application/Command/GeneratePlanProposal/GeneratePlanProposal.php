<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\GeneratePlanProposal;

use Logistics\Shared\Application\Command\Command;

/**
 * Pide generar la propuesta pedido→camión para un día: arma el plan con los envíos dados, resuelve orden
 * y ETA, y deja el plan propuesto (con secuencia, ETA y plan de carga).
 *
 * La hora de salida del depósito viene en formato ISO-8601 (p. ej. '2026-10-13T07:30:00-04:00').
 *
 * `keepOrder`:
 *  - false (por defecto, comportamiento histórico): el motor de rutas REORDENA las paradas; la primera
 *    recibida es el punto de salida y queda fija.
 *  - true: se RESPETA el orden recibido (el viaje ya lo ordenó el planificador en el asistente); solo se
 *    calcula la ETA de cada parada saliendo de la base del camión.
 */
final readonly class GeneratePlanProposal implements Command
{
    /**
     * @param list<ProposalStopInput> $stops
     */
    public function __construct(
        public string $planDate,
        public string $vehicleId,
        public string $departAt,
        public array $stops,
        public bool $keepOrder = false,
    ) {}
}
