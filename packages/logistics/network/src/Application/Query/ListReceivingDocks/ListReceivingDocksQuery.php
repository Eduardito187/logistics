<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Query\ListReceivingDocks;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los andenes de recepción de un almacén central.
 */
final readonly class ListReceivingDocksQuery implements Query
{
    public function __construct(
        public string $warehouseId,
    ) {}
}
