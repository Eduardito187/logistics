<?php

declare(strict_types=1);

namespace Commerce\Cart\Application\Command\MarkCartConverted;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de marcar el carrito como convertido en orden (emite el hecho `cart.converted`).
 */
final readonly class MarkCartConverted implements Command
{
    public function __construct(
        public string $cartId,
    ) {}
}
