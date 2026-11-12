<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\GetDriverRouteLifecycle;

use Logistics\Shared\Application\Query\Query;

/**
 * Estado del ciclo de vida de la ruta del día (inicio/cierre), ubicada con la misma regla que los comandos
 * de inicio y cierre (DriverRouteLocator). Sin enriquecimiento ni geometría: respuesta liviana.
 */
final readonly class GetDriverRouteLifecycle implements Query
{
    public function __construct(
        public string $vehicleId,
        public ?string $routeDate = null,
        public ?string $occurredAt = null,
    ) {}
}
