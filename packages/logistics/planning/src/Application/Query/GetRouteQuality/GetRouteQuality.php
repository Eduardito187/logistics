<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Query\GetRouteQuality;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta las métricas de CALIDAD de un plan (R-06): distancia total, tiempo estimado y ocupación del
 * camión. Lectura pura, sin efectos.
 */
final readonly class GetRouteQuality implements Query
{
    public function __construct(
        public string $planId,
    ) {}
}
