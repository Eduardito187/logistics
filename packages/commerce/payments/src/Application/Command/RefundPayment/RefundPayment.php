<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Command\RefundPayment;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de reembolsar un pago ya capturado (reversa el cobro).
 */
final readonly class RefundPayment implements Command
{
    public function __construct(
        public string $paymentId,
    ) {}
}
