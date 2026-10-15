<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\AssembleDailyRoute;

use Logistics\Shared\Application\Command\Command;

/**
 * Pide armar la ruta del día de un camión: la secuencia ordenada de TODAS sus paradas, para que el
 * chofer deje una y proceda con la siguiente. Las paradas vienen del plan publicado (planificación),
 * ya ordenadas por su secuencia.
 */
final readonly class AssembleDailyRoute implements Command
{
    /**
     * @param list<RouteStopInput> $stops
     */
    public function __construct(
        public string $vehicleId,
        public string $routeDate,
        public array $stops,
    ) {}
}
