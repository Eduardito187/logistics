<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\ListMaintenanceOrders;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista órdenes de mantenimiento. Opcionalmente filtra por vehículo y/o estado.
 */
final readonly class ListMaintenanceOrders implements Query
{
    public function __construct(
        public ?string $vehicleId = null,
        public ?string $status = null,
    ) {}
}
