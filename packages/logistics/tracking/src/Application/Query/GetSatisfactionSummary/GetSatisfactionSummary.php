<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Query\GetSatisfactionSummary;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta el resumen de satisfacción (K-06) para el back office: volumen, promedio, NPS y los
 * comentarios más recientes (hasta recentLimit).
 */
final readonly class GetSatisfactionSummary implements Query
{
    public function __construct(
        public int $recentLimit = 10,
    ) {}
}
