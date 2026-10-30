<?php

declare(strict_types=1);

namespace Commerce\Orders\Application\Command\CancelOrder;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de cancelar una orden (solo posible mientras está pendiente de pago).
 */
final readonly class CancelOrder implements Command
{
    public function __construct(
        public string $orderId,
    ) {}
}
