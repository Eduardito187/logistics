<?php

declare(strict_types=1);

namespace Commerce\Catalog\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Commerce\Catalog\Application\Query\ListProducts\ListProducts;
use Commerce\Catalog\Application\ReadModel\ProductView;
use Commerce\Catalog\Domain\ValueObject\ProductStatus;
use Commerce\Catalog\Presentation\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels: lista los productos del catálogo, con filtro opcional por estado de publicación.
 *
 * Lee vía QueryBus (ListProducts → list<ProductView>). La validación es inline por ser un filtro simple
 * de query string. La paginación es opcional y aditiva (E-02): si el cliente la pide (`per_page`, `page`
 * o `cursor`), se trocea la colección devuelta y se adjunta `meta`; si no, se devuelve la lista completa.
 */
final class ListProductsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $statuses = array_map(
                static fn (ProductStatus $status): string => $status->value,
                ProductStatus::cases(),
            );

            /** @var array{status?: string|null} $filters */
            $filters = $request->validate([
                'status' => ['nullable', 'string', Rule::in($statuses)],
            ]);

            /** @var list<ProductView> $views */
            $views = $this->queries->ask(new ListProducts(
                status: $filters['status'] ?? null,
            ));

            $items = array_map(static fn (ProductView $view): array => $view->toArray(), $views);

            // Paginación opcional (E-02): solo se activa si el cliente la pide; si no, lista completa.
            if (ApiPagination::requested($request)) {
                return new JsonResponse(ApiPagination::paginate($items, $request));
            }

            return $this->data($items);
        });
    }
}
