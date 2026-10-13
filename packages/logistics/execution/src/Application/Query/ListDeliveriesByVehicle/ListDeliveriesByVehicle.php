<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\ListDeliveriesByVehicle;

use Logistics\Shared\Application\Query\Query;

/**
 * Devuelve las entregas asignadas a un vehículo: la hoja de ruta del chofer.
 */
final readonly class ListDeliveriesByVehicle implements Query
{
    public function __construct(
        public string $vehicleId,
    ) {}
}
