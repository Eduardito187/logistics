<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Query\CompareRoutingBackends;

use Logistics\Shared\Application\Query\Query;

/**
 * Compara los backends de ruteo (builtin/osrm/vroom) para un plan (R-06): corre cada backend sobre el
 * mismo conjunto de paradas del plan y devuelve costo/distancia/tiempo por backend. Es una consulta de
 * ANÁLISIS: no modifica el plan.
 *
 * Nota: a diferencia de las consultas triviales, esta ejecuta los backends (puede implicar I/O a OSRM/
 * VROOM); está pensada para uso bajo demanda desde el panel/analítica, no para un listado caliente.
 */
final readonly class CompareRoutingBackends implements Query
{
    public function __construct(
        public string $planId,
    ) {}
}
