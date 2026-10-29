<?php

declare(strict_types=1);

namespace Commerce\Cart\Application\Command\RemoveItem;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de remover una línea (por SKU) del carrito.
 */
final readonly class RemoveItem implements Command
{
    public function __construct(
        public string $cartId,
        public string $sku,
    ) {}
}
