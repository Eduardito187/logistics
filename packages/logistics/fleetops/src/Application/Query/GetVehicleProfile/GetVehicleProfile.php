<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetVehicleProfile;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el perfil técnico de un vehículo por su id de vehículo (fleet).
 */
final readonly class GetVehicleProfile implements Query
{
    public function __construct(
        public string $vehicleId,
    ) {}
}
