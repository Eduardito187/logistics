<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\CheckDispatchDay;

use Logistics\Shared\Application\Query\Query;

/**
 * Pregunta si un (municipio, fecha) está disponible para despacho: si está bloqueado (feriado u otro
 * motivo, propio o global) y si cae dentro del horizonte activo de cupos. `municipalityId` null consulta
 * el alcance global.
 */
final readonly class CheckDispatchDayQuery implements Query
{
    public function __construct(
        public string $date,
        public ?string $municipalityId = null,
    ) {}
}
