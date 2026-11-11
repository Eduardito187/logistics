<?php

declare(strict_types=1);

namespace Commerce\Orders\Application\Command\SignOrderContract;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de registrar la firma del contrato de crédito de una orden (hecho del POS/sistema de crédito).
 * Idempotente. Habilita el paso a procesando según el plan de financiamiento.
 */
final readonly class SignOrderContract implements Command
{
    public function __construct(
        public string $orderId,
        public ?string $reference = null,
    ) {}
}
