<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Command\InitiatePayment;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de iniciar el cobro de una orden con un método de pago.
 *
 * `orderId` es el UUID de la orden (payments no conoce el contexto orders: lo referencia por id).
 */
final readonly class InitiatePayment implements Command
{
    public function __construct(
        public string $orderId,
        public int $amountCents,
        public string $currency,
        public string $method,
    ) {}
}
