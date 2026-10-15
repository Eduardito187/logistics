<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetVehicleExpenseTotal;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el total gastado por un vehículo en un rango de fechas (ISO 8601), para el TCO.
 */
final readonly class GetVehicleExpenseTotal implements Query
{
    public function __construct(
        public string $vehicleId,
        public ?string $from = null,
        public ?string $to = null,
    ) {}
}
