<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\GetRouteStop;

use Logistics\Shared\Application\Query\Query;

/**
 * Parada de la ruta que contiene al envío (con su enriquecimiento cross-context). Null si el envío no es
 * parada de ninguna ruta.
 */
final readonly class GetRouteStop implements Query
{
    public function __construct(
        public string $shipmentId,
    ) {}
}
