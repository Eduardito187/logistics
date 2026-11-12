<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\Query\GetProductBySku;

use Commerce\Catalog\Application\ReadModel\ProductView;
use Commerce\Catalog\Domain\Contract\ProductRepository;
use Commerce\Catalog\Domain\ValueObject\Sku;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve un producto por su SKU canónico (normalizado a mayúsculas), o null si no existe.
 *
 * @implements QueryHandler<GetProductBySku, ProductView|null>
 */
final readonly class GetProductBySkuHandler implements QueryHandler
{
    public function __construct(
        private ProductRepository $products,
    ) {}

    public function handle(Query $query): ?ProductView
    {
        assert($query instanceof GetProductBySku);

        if (trim($query->sku) === '') {
            return null;
        }

        $product = $this->products->findByCanonicalSku(Sku::of($query->sku));

        return $product !== null ? ProductView::fromDomain($product) : null;
    }
}
