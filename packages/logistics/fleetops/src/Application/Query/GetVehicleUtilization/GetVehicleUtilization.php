<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetVehicleUtilization;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide la utilización de un vehículo en un rango de fechas (ISO 8601): salidas, km recorridos, horas en
 * ruta y última lectura de odómetro.
 */
final readonly class GetVehicleUtilization implements Query
{
    public function __construct(
        public string $vehicleId,
        public string $from,
        public string $to,
    ) {}
}
