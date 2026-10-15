<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Service;

use Logistics\Fleet\Domain\Contract\CrewRepository;
use Logistics\Fleet\Domain\ValueObject\ShiftRules;
use Logistics\Fleet\Domain\ValueObject\WorkShift;

/**
 * Servicio de dominio que valida la asignación de un turno a una tripulación.
 *
 * Es cruce entre agregados (una persona puede estar en varias tripulaciones), por eso no vive dentro de
 * Crew: reúne los turnos ya asignados a cada integrante vía el repositorio y aplica las reglas de turnos
 * y descansos sobre el turno candidato. Si algo no cumple, las reglas lanzan FleetException.
 */
final readonly class CrewShiftScheduler
{
    public function __construct(
        private CrewRepository $crews,
    ) {}

    /**
     * Valida que el turno propuesto cumpla la ventana y jornada, y que no choque (solape ni falta de
     * descanso) con los turnos ya asignados al chofer ni a cada ayudante de la tripulación.
     *
     * @param list<string> $memberIds ids de chofer y ayudantes (en texto, como los guarda la tripulación)
     */
    public function ensureAssignable(WorkShift $shift, ShiftRules $rules, array $memberIds): void
    {
        $rules->ensureWindowAndDuration($shift);

        // Margen de un día a cada lado para alcanzar turnos vecinos que afecten el descanso aunque caigan
        // en otra fecha (p. ej. un turno que terminó anoche y otro que empieza esta madrugada).
        $from = $shift->startsAt->modify('-1 day');
        $to = $shift->endsAt->modify('+1 day');

        foreach (array_unique($memberIds) as $memberId) {
            $existing = $this->crews->workShiftsForMemberBetween($memberId, $from, $to);
            $rules->ensureRestRespected($shift, $existing);
        }
    }
}
