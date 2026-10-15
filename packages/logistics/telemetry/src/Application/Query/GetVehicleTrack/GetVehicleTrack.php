<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\GetVehicleTrack;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide la traza (recorrido) de un vehículo entre dos instantes. `from` y `to` son fechas ISO8601.
 */
final readonly class GetVehicleTrack implements Query
{
    public function __construct(
        public string $vehicleId,
        public string $from,
        public string $to,
    ) {}
}
