<?php

declare(strict_types=1);

namespace Commerce\Orders\Application\Command\MarkOrderPaid;

use Logistics\Shared\Application\Command\Command;

/**
 * Confirma el pago de una orden. Lo dispara el consumidor del evento `payments.captured` (puente
 * payments → orders por outbox). Idempotente.
 */
final readonly class MarkOrderPaid implements Command
{
    public function __construct(
        public string $orderId,
        public string $gatewayRef,
    ) {}
}
