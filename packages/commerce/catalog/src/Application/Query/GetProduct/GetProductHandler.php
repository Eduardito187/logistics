<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\Query\GetProduct;

use Commerce\Catalog\Application\ReadModel\ProductView;
use Commerce\Catalog\Domain\Contract\ProductRepository;
use Commerce\Catalog\Domain\ValueObject\ProductId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve un producto por su id, o null si no existe.
 *
 * @implements QueryHandler<GetProduct, ProductView|null>
 */
final readonly class GetProductHandler implements QueryHandler
{
    public function __construct(
        private ProductRepository $products,
    ) {}

    public function handle(Query $query): ?ProductView
    {
        assert($query instanceof GetProduct);

        $product = $this->products->findById(ProductId::fromString($query->productId));

        return $product !== null ? ProductView::fromDomain($product) : null;
    }
}
