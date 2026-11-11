<?php

declare(strict_types=1);

namespace Commerce\Orders\Application\Command\RecordInitialPayment;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de registrar el pago de la cuota inicial de una orden de crédito con inicial (hecho del
 * POS/sistema de crédito). Idempotente. Junto a la firma del contrato habilita el paso a procesando.
 */
final readonly class RecordInitialPayment implements Command
{
    public function __construct(
        public string $orderId,
        public ?string $reference = null,
    ) {}
}
