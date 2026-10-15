<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetFuelConsumption;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el consumo de combustible de un vehículo en un rango de fechas (ISO 8601).
 */
final readonly class GetFuelConsumption implements Query
{
    public function __construct(
        public string $vehicleId,
        public string $from,
        public string $to,
    ) {}
}
