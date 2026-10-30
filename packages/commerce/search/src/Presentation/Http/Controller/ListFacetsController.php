<?php

declare(strict_types=1);

namespace Commerce\Search\Presentation\Http\Controller;

use Commerce\Search\Application\Query\ListFacets\ListFacets;
use Commerce\Search\Application\ReadModel\CategoryFacet;
use Commerce\Search\Domain\ValueObject\SearchCriteria;
use Commerce\Search\Presentation\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Facetas de navegación: cantidad de productos por categoría para un estado dado (default published).
 */
final class ListFacetsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $status = $request->query('status');
            $status = is_string($status) && $status !== '' ? $status : SearchCriteria::DEFAULT_STATUS;

            /** @var list<CategoryFacet> $facets */
            $facets = $this->queries->ask(new ListFacets($status));

            $items = array_map(static fn (CategoryFacet $facet): array => $facet->toArray(), $facets);

            return $this->data($items);
        });
    }
}
