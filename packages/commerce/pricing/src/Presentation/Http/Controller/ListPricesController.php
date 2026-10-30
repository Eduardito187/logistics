<?php

declare(strict_types=1);

namespace Commerce\Pricing\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Commerce\Pricing\Application\Query\ListPrices\ListPrices;
use Commerce\Pricing\Application\ReadModel\PriceView;
use Commerce\Pricing\Presentation\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta channels: lista los precios (ítems) de una lista de precios (consulta ListPrices).
 *
 * Lee vía QueryBus (ListPrices → list<PriceView>). La lista objetivo se identifica por `price_list_id`
 * en la query string. La paginación es opcional y aditiva (E-02): si el cliente la pide (`per_page`,
 * `page` o `cursor`), se trocea la colección devuelta y se adjunta `meta`; si no, se devuelve completa.
 */
final class ListPricesController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var array{price_list_id: string} $params */
            $params = $request->validate([
                'price_list_id' => ['required', 'uuid'],
            ]);

            /** @var list<PriceView> $views */
            $views = $this->queries->ask(new ListPrices(
                priceListId: $params['price_list_id'],
            ));

            $items = array_map(static fn (PriceView $view): array => $view->toArray(), $views);

            // Paginación opcional (E-02): solo se activa si el cliente la pide; si no, lista completa.
            if (ApiPagination::requested($request)) {
                return new JsonResponse(ApiPagination::paginate($items, $request));
            }

            return $this->data($items);
        });
    }
}
