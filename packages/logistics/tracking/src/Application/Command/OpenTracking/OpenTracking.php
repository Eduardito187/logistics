<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\OpenTracking;

use Logistics\Shared\Application\Command\Command;

/**
 * Abre el seguimiento de un PEDIDO: lo dispara la creación de la orden (`orders.order_placed`), antes de
 * que exista el envío. El estado inicial es «pendiente» y el token público lo genera el handler.
 *
 * Idempotente por `orderReference`: reabrir el seguimiento de una orden ya seguida no crea otro.
 */
final readonly class OpenTracking implements Command
{
    public function __construct(
        public string $orderReference,
        public int $remainingStops = 0,
    ) {}
}
