<?php

declare(strict_types=1);

namespace Commerce\Cart\Application\Command\OpenCart;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de abrir un carrito nuevo para una sesión del storefront. El cliente es opcional
 * (null = carrito de invitado).
 */
final readonly class OpenCart implements Command
{
    public function __construct(
        public string $sessionId,
        public ?string $customerId = null,
        public string $currency = 'BOB',
    ) {}
}
