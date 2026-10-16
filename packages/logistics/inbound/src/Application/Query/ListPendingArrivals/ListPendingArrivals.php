<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Query\ListPendingArrivals;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los ASNs agendados pendientes de llegada (estado Scheduled), opcionalmente de un almacén.
 */
final readonly class ListPendingArrivals implements Query
{
    public function __construct(
        public ?string $warehouseId = null,
    ) {}
}
