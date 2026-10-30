<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Application\Query\ListInvoices;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista las facturas, con filtro opcional por estado (draft|issued|cancelled).
 */
final readonly class ListInvoices implements Query
{
    public function __construct(
        public ?string $status = null,
    ) {}
}
