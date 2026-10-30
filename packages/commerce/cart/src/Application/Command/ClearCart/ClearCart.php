<?php

declare(strict_types=1);

namespace Commerce\Cart\Application\Command\ClearCart;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de vaciar todas las líneas del carrito.
 */
final readonly class ClearCart implements Command
{
    public function __construct(
        public string $cartId,
    ) {}
}
