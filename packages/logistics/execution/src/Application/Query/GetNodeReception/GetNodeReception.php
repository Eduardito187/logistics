<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\GetNodeReception;

use Logistics\Shared\Application\Query\Query;

/**
 * Devuelve el estado de la recepción de un envío en un nodo destino (esperados, recibidos, faltantes,
 * sobrantes). Null si todavía no se abrió.
 */
final readonly class GetNodeReception implements Query
{
    public function __construct(
        public string $shipmentId,
        public string $destinationNodeId,
    ) {}
}
