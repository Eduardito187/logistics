<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\Command\AddProductVariant;

use Commerce\Catalog\Domain\Contract\ProductRepository;
use Commerce\Catalog\Domain\Exception\CatalogException;
use Commerce\Catalog\Domain\ValueObject\ProductId;
use Commerce\Catalog\Domain\ValueObject\ProductVariant;
use Commerce\Catalog\Domain\ValueObject\Sku;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Agrega una variante a un producto existente (SKU único dentro del producto).
 *
 * @implements CommandHandler<AddProductVariant>
 */
final readonly class AddProductVariantHandler implements CommandHandler
{
    public function __construct(
        private ProductRepository $products,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof AddProductVariant);

        $product = $this->products->findById(ProductId::fromString($command->productId));
        if ($product === null) {
            throw CatalogException::because('El producto al que se agrega la variante no existe.');
        }

        $product->addVariant(ProductVariant::of(
            Sku::of($command->sku),
            $command->name,
            $command->attributes,
        ));

        $this->products->save($product);
    }
}
