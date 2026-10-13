<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\ListVehicles;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los vehículos de la flota. Opcionalmente filtra por estado.
 */
final readonly class ListVehicles implements Query
{
    public function __construct(
        public ?string $status = null,
    ) {}
}
