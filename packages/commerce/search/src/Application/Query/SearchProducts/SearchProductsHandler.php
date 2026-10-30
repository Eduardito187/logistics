<?php

declare(strict_types=1);

namespace Commerce\Search\Application\Query\SearchProducts;

use Commerce\Search\Application\Contract\ProductSearchReader;
use Commerce\Search\Application\ReadModel\SearchPage;
use Commerce\Search\Domain\ValueObject\SearchCriteria;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Valida el criterio (VO de dominio) y delega la búsqueda al puerto de lectura, devolviendo la página.
 *
 * @implements QueryHandler<SearchProducts, SearchPage>
 */
final readonly class SearchProductsHandler implements QueryHandler
{
    public function __construct(
        private ProductSearchReader $reader,
    ) {}

    public function handle(Query $query): SearchPage
    {
        assert($query instanceof SearchProducts);

        $criteria = SearchCriteria::of(
            term: $query->term,
            categoryId: $query->categoryId,
            status: $query->status,
            limit: $query->limit,
            cursor: $query->cursor,
        );

        return $this->reader->search($criteria);
    }
}
