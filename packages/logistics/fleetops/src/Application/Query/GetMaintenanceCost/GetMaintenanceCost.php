<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetMaintenanceCost;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el costo de mantenimiento de un vehículo en un rango de fechas (ISO 8601).
 */
final readonly class GetMaintenanceCost implements Query
{
    public function __construct(
        public string $vehicleId,
        public string $from,
        public string $to,
    ) {}
}
