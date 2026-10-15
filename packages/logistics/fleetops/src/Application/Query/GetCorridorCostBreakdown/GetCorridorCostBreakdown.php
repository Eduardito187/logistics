<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetCorridorCostBreakdown;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el desglose de costo de la flota propia por corredor entre dos fechas (YYYY-MM-DD).
 */
final readonly class GetCorridorCostBreakdown implements Query
{
    public function __construct(
        public string $from,
        public string $to,
    ) {}
}
