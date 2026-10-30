<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\Query\ListCategories;

use Commerce\Catalog\Application\ReadModel\CategoryView;
use Commerce\Catalog\Domain\Contract\CategoryRepository;
use Commerce\Catalog\Domain\Model\Category;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Lista todas las categorías como ReadModels.
 *
 * @implements QueryHandler<ListCategories, list<CategoryView>>
 */
final readonly class ListCategoriesHandler implements QueryHandler
{
    public function __construct(
        private CategoryRepository $categories,
    ) {}

    /**
     * @return list<CategoryView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListCategories);

        return array_map(
            static fn (Category $category): CategoryView => CategoryView::fromDomain($category),
            $this->categories->all(),
        );
    }
}
