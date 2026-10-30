<?php

declare(strict_types=1);

namespace Commerce\Orders\Application\Query\ListOrders;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista órdenes, opcionalmente filtrando por estado (pending|paid|fulfilling|cancelled).
 */
final readonly class ListOrders implements Query
{
    public function __construct(
        public ?string $status = null,
    ) {}
}
