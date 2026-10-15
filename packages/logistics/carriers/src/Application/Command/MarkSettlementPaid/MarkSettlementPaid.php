<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\MarkSettlementPaid;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de marcar una liquidación aprobada como pagada, con la referencia del pago.
 */
final readonly class MarkSettlementPaid implements Command
{
    public function __construct(
        public string $settlementId,
        public string $paymentReference,
    ) {}
}
