<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\GetVehicleEtas;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide la ETA dinámica de las paradas restantes de la ruta del día de un vehículo.
 */
final readonly class GetVehicleEtas implements Query
{
    public function __construct(
        public string $vehicleId,
    ) {}
}
