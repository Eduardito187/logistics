<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Application\Query\GetInvoiceByOrder;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide la factura asociada a una orden (por su UUID).
 */
final readonly class GetInvoiceByOrder implements Query
{
    public function __construct(
        public string $orderId,
    ) {}
}
