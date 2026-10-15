<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetVehicleCostBreakdown;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el desglose de costo de toda la flota por vehículo entre dos fechas (YYYY-MM-DD).
 */
final readonly class GetVehicleCostBreakdown implements Query
{
    public function __construct(
        public string $from,
        public string $to,
    ) {}
}
