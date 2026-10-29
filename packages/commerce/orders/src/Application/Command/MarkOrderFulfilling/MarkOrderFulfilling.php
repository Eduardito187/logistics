<?php

declare(strict_types=1);

namespace Commerce\Orders\Application\Command\MarkOrderFulfilling;

use Logistics\Shared\Application\Command\Command;

/**
 * Marca que arrancó el fulfillment de la orden (se solicitó el envío). Lo dispara el puente
 * orders → shipments tras crear el envío. Idempotente.
 */
final readonly class MarkOrderFulfilling implements Command
{
    public function __construct(
        public string $orderId,
    ) {}
}
