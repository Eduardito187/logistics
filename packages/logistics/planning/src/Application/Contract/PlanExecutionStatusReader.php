<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Contract;

/**
 * Lee el estado de EJECUCIÓN de la ruta del día de un plan, sin acoplar Planificación al dominio de
 * Ejecución (lectura cross-context por primitivos). El estado de ejecución vive en la ruta del chofer
 * (`execution_driver_routes`), no en el Plan: el Plan tiene DRAFT/PROPOSED/PUBLISHED; la ruta tiene
 * PLANNED/IN_PROGRESS/COMPLETED/SUSPENDED.
 *
 * Devuelve un enum ESTABLE para la UI (no el estado interno de ejecución): `NOT_STARTED` (ruta armada sin
 * arrancar), `IN_PROGRESS` (en curso o suspendida) o `COMPLETED` (cerrada). Sin ruta ese día, la clave no
 * aparece (la vista queda en null).
 */
interface PlanExecutionStatusReader
{
    /**
     * @param  list<array{vehicle_id: string, plan_date: string}> $slots camión + día de cada plan
     * @return array<string, string>                              clave "vehicle_id|plan_date" → NOT_STARTED|IN_PROGRESS|COMPLETED
     */
    public function statusesFor(array $slots): array;
}
