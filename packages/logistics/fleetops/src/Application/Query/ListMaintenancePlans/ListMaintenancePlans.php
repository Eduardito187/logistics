<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\ListMaintenancePlans;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista planes de mantenimiento. Opcionalmente filtra por vehículo.
 */
final readonly class ListMaintenancePlans implements Query
{
    public function __construct(
        public ?string $vehicleId = null,
    ) {}
}
