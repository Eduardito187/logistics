<?php

declare(strict_types=1);

namespace Commerce\Cart\Application\Command\UpdateItemQty;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de fijar la cantidad de un SKU del carrito. Cantidad 0 remueve la línea.
 */
final readonly class UpdateItemQty implements Command
{
    public function __construct(
        public string $cartId,
        public string $sku,
        public int $qty,
    ) {}
}
