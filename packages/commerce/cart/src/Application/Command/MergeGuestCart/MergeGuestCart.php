<?php

declare(strict_types=1);

namespace Commerce\Cart\Application\Command\MergeGuestCart;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de fusionar un carrito de invitado dentro del carrito destino (típicamente al iniciar
 * sesión): las líneas del origen se suman por SKU en el destino.
 */
final readonly class MergeGuestCart implements Command
{
    public function __construct(
        public string $targetCartId,
        public string $guestCartId,
    ) {}
}
