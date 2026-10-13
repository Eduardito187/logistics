<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetMaintenanceOrder;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide una orden de mantenimiento por su id.
 */
final readonly class GetMaintenanceOrder implements Query
{
    public function __construct(
        public string $orderId,
    ) {}
}
