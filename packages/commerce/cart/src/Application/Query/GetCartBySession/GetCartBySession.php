<?php

declare(strict_types=1);

namespace Commerce\Cart\Application\Query\GetCartBySession;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta el carrito abierto de una sesión del storefront.
 */
final readonly class GetCartBySession implements Query
{
    public function __construct(
        public string $sessionId,
    ) {}
}
