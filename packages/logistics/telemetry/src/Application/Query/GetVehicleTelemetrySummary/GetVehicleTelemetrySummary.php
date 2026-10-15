<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\GetVehicleTelemetrySummary;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el resumen de telemetría de un vehículo en un día (fecha YYYY-MM-DD).
 */
final readonly class GetVehicleTelemetrySummary implements Query
{
    public function __construct(
        public string $vehicleId,
        public string $date,
    ) {}
}
