<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\GeneratePlanProposal;

use Logistics\Shared\Application\Command\Command;

/**
 * Pide generar la propuesta automática pedido→camión para un día: arma el plan con los envíos dados,
 * corre el motor de rutas y deja el plan propuesto (con secuencia, ETA y plan de carga).
 *
 * La hora de salida del depósito viene en formato ISO-8601 (p. ej. '2026-10-13T07:30:00-04:00').
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
    ) {}
}
