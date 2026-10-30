<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\Command\AddProductVariant;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de agregar una variante (SKU + atributos) a un producto.
 */
final readonly class AddProductVariant implements Command
{
    /**
     * @param array<string, string> $attributes
     */
    public function __construct(
        public string $productId,
        public string $sku,
        public string $name,
        public array $attributes = [],
    ) {}
}
