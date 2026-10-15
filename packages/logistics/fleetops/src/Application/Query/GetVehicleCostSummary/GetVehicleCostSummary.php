<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetVehicleCostSummary;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el resumen de costos (TCO + costo por km) de un vehículo entre dos fechas (YYYY-MM-DD).
 */
final readonly class GetVehicleCostSummary implements Query
{
    public function __construct(
        public string $vehicleId,
        public string $from,
        public string $to,
    ) {}
}
