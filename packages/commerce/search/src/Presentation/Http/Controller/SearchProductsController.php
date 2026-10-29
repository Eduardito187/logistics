<?php

declare(strict_types=1);

namespace Commerce\Search\Presentation\Http\Controller;

use Commerce\Search\Application\Query\SearchProducts\SearchProducts;
use Commerce\Search\Application\ReadModel\SearchPage;
use Commerce\Search\Domain\ValueObject\SearchCriteria;
use Commerce\Search\Presentation\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Busca productos del catálogo para el storefront: texto libre (FTS) + filtros, paginando por cursor.
 *
 * Query string: `q` (término), `category_id`, `status` (default published), `limit` (1..100, default 20),
 * `cursor` (opaco, de una respuesta previa). Devuelve `{ items, next_cursor, total }` bajo `data`.
 */
final class SearchProductsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var SearchPage $page */
            $page = $this->queries->ask(new SearchProducts(
                term: $this->nullableString($request, 'q'),
                categoryId: $this->nullableString($request, 'category_id'),
                status: $this->nullableString($request, 'status') ?? SearchCriteria::DEFAULT_STATUS,
                limit: $this->limit($request),
                cursor: $this->nullableString($request, 'cursor'),
            ));

            return $this->data($page->toArray());
        });
    }

    private function nullableString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Límite solicitado; cae al default si no es numérico. El rango 1..100 lo valida el VO de dominio.
     */
    private function limit(Request $request): int
    {
        $value = $request->query('limit');

        return is_numeric($value) ? (int) $value : SearchCriteria::DEFAULT_LIMIT;
    }
}
