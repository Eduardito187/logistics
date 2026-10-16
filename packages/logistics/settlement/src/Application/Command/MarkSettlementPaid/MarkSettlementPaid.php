<?php

declare(strict_types=1);

namespace Logistics\Settlement\Application\Command\MarkSettlementPaid;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de marcar como pagada una liquidación confirmada, con la referencia del pago.
 */
final readonly class MarkSettlementPaid implements Command
{
    public function __construct(
        public string $settlementId,
        public string $paymentReference,
    ) {}
}
