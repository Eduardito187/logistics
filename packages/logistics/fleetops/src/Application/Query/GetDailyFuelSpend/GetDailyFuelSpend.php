<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetDailyFuelSpend;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el gasto de combustible de un día concreto (fecha 'Y-m-d').
 */
final readonly class GetDailyFuelSpend implements Query
{
    public function __construct(
        public string $date,
    ) {}
}
