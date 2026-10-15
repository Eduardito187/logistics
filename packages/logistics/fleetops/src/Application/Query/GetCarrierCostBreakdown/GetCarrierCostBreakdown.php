<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetCarrierCostBreakdown;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el desglose de costo de TERCEROS (transportistas) entre dos fechas (YYYY-MM-DD), a partir de las
 * liquidaciones del contexto carriers.
 */
final readonly class GetCarrierCostBreakdown implements Query
{
    public function __construct(
        public string $from,
        public string $to,
    ) {}
}
