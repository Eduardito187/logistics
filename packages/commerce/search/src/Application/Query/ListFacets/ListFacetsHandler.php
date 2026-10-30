<?php

declare(strict_types=1);

namespace Commerce\Search\Application\Query\ListFacets;

use Commerce\Search\Application\Contract\ProductSearchReader;
use Commerce\Search\Application\ReadModel\CategoryFacet;
use Commerce\Search\Domain\ValueObject\SearchCriteria;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Valida el estado (vía el VO) y delega el conteo por categoría al puerto de lectura.
 *
 * @implements QueryHandler<ListFacets, list<CategoryFacet>>
 */
final readonly class ListFacetsHandler implements QueryHandler
{
    public function __construct(
        private ProductSearchReader $reader,
    ) {}

    /**
     * @return list<CategoryFacet>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListFacets);

        // Reusa la validación de estado del VO de dominio (lanza SearchException si no es permitido).
        $criteria = SearchCriteria::of(status: $query->status);

        return $this->reader->facetsByCategory($criteria->status);
    }
}
