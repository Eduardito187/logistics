<?php

declare(strict_types=1);

namespace Commerce\Pricing\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Commerce\Pricing\Application\Query\ListPriceLists\ListPriceLists;
use Commerce\Pricing\Application\ReadModel\PriceListView;
use Commerce\Pricing\Domain\ValueObject\Channel;
use Commerce\Pricing\Presentation\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta integrations (gestión PIM): lista las listas de precios, con filtro opcional por canal.
 *
 * Lee vía QueryBus (ListPriceLists → list<PriceListView>). La validación es inline por ser un filtro
 * simple de query string. La paginación es opcional y aditiva (E-02): si el cliente la pide (`per_page`,
 * `page` o `cursor`), se trocea la colección devuelta y se adjunta `meta`; si no, se devuelve completa.
 */
final class ListPriceListsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $channels = array_map(
                static fn (Channel $channel): string => $channel->value,
                Channel::cases(),
            );

            /** @var array{channel?: string|null} $filters */
            $filters = $request->validate([
                'channel' => ['nullable', 'string', Rule::in($channels)],
            ]);

            /** @var list<PriceListView> $views */
            $views = $this->queries->ask(new ListPriceLists(
                channel: $filters['channel'] ?? null,
            ));

            $items = array_map(static fn (PriceListView $view): array => $view->toArray(), $views);

            // Paginación opcional (E-02): solo se activa si el cliente la pide; si no, lista completa.
            if (ApiPagination::requested($request)) {
                return new JsonResponse(ApiPagination::paginate($items, $request));
            }

            return $this->data($items);
        });
    }
}
