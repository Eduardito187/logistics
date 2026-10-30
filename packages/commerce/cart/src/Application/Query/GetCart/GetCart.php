<?php

declare(strict_types=1);

namespace Commerce\Cart\Application\Query\GetCart;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta un carrito por su id.
 */
final readonly class GetCart implements Query
{
    public function __construct(
        public string $cartId,
    ) {}
}
