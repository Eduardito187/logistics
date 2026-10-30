<?php

declare(strict_types=1);

namespace Commerce\Cart\Application\Command\AddItem;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de agregar una línea al carrito. El precio unitario (en centavos) viaja YA resuelto por
 * `pricing`/storefront: el carrito no consulta precios.
 */
final readonly class AddItem implements Command
{
    public function __construct(
        public string $cartId,
        public string $sku,
        public string $productName,
        public int $unitPriceCents,
        public int $qty = 1,
    ) {}
}
