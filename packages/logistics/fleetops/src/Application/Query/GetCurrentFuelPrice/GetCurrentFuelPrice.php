<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetCurrentFuelPrice;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el precio vigente (a la fecha actual) de un tipo de combustible.
 */
final readonly class GetCurrentFuelPrice implements Query
{
    public function __construct(
        public string $fuelType,
    ) {}
}
