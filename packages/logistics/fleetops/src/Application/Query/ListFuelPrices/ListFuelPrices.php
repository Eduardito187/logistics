<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\ListFuelPrices;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los precios de combustible registrados. Opcionalmente filtra por tipo de combustible.
 */
final readonly class ListFuelPrices implements Query
{
    public function __construct(
        public ?string $fuelType = null,
    ) {}
}
