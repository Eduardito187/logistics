<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Application\Query\GetInvoice;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide una factura por su id.
 */
final readonly class GetInvoice implements Query
{
    public function __construct(
        public string $invoiceId,
    ) {}
}
