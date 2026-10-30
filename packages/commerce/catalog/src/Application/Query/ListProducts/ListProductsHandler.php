<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\Query\ListProducts;

use Commerce\Catalog\Application\ReadModel\ProductView;
use Commerce\Catalog\Domain\Contract\ProductRepository;
use Commerce\Catalog\Domain\Model\Product;
use Commerce\Catalog\Domain\ValueObject\ProductStatus;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Lista productos (todos o filtrados por estado) como ReadModels.
 *
 * @implements QueryHandler<ListProducts, list<ProductView>>
 */
final readonly class ListProductsHandler implements QueryHandler
{
    public function __construct(
        private ProductRepository $products,
    ) {}

    /**
     * @return list<ProductView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListProducts);

        $products = $query->status !== null
            ? $this->products->listByStatus(ProductStatus::from($query->status))
            : $this->products->all();

        return array_map(
            static fn (Product $product): ProductView => ProductView::fromDomain($product),
            $products,
        );
    }
}
