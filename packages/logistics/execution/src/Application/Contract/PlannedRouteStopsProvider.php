<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Contract;

use Logistics\Execution\Application\Command\AssembleDailyRoute\RouteStopInput;

/**
 * Fuente de las paradas del PLAN PUBLICADO para armar la ruta del día del chofer (contrato con la app:
 * el backend ensambla desde el plan, el cliente NO manda paradas). La implementación lee la tabla del
 * contexto `planning` en solo lectura (query builder), sin acoplar Ejecución al dominio de planificación.
 */
interface PlannedRouteStopsProvider
{
    /**
     * Paradas del plan PUBLICADO del vehículo para esa fecha (Y-m-d), ya ordenadas por secuencia. Lista
     * vacía si no hay plan publicado (el caller decide qué responder).
     *
     * @return list<RouteStopInput>
     */
    public function forVehicleOnDate(string $vehicleId, string $routeDate): array;
}
