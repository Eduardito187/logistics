<?php

declare(strict_types=1);

namespace Logistics\Sellers\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Sellers\Application\Query\ListSellers\ListSellers;
use Logistics\Sellers\Application\ReadModel\SellerView;
use Logistics\Sellers\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista los sellers como proyecciones de lectura, con filtro opcional por estado (consulta ListSellers).
 */
final class ListSellersController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $status = $request->query('status');
            $status = is_string($status) && $status !== '' ? $status : null;

            /** @var list<SellerView> $sellers */
            $sellers = $this->queries->ask(new ListSellers(status: $status));

            $items = array_map(static fn (SellerView $view): array => $view->toArray(), $sellers);

            // Paginación opcional y aditiva (E-02): solo si el cliente la pide; si no, colección completa.
            if (ApiPagination::requested($request)) {
                $page = ApiPagination::paginate($items, $request);

                return $this->data($page['data'], 200, $page['meta']);
            }

            return $this->data($items);
        });
    }
}
