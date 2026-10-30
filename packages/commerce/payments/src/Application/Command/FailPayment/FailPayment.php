<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Command\FailPayment;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de marcar un pago como fallido (la pasarela rechazó el cobro), con el motivo legible.
 */
final readonly class FailPayment implements Command
{
    public function __construct(
        public string $paymentId,
        public string $reason,
    ) {}
}
