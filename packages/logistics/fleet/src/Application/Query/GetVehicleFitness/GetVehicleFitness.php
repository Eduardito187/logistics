<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\GetVehicleFitness;

use Logistics\Shared\Application\Query\Query;

/**
 * Aptitud de un vehículo para despachar en una fecha (Y-m-d).
 */
final readonly class GetVehicleFitness implements Query
{
    public function __construct(
        public string $vehicleId,
        public string $date,
    ) {}
}
