<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Application\Command\CancelInvoice;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de anular una factura por su id.
 */
final readonly class CancelInvoice implements Command
{
    public function __construct(
        public string $invoiceId,
    ) {}
}
