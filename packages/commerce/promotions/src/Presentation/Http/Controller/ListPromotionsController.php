<?php

declare(strict_types=1);

namespace Commerce\Promotions\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Commerce\Promotions\Application\Query\ListPromotions\ListPromotions;
use Commerce\Promotions\Application\ReadModel\PromotionView;
use Commerce\Promotions\Domain\ValueObject\PromotionStatus;
use Commerce\Promotions\Presentation\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta integrations: lista las promociones, con filtro opcional por estado (consulta ListPromotions).
 *
 * Lee vía QueryBus (ListPromotions → list<PromotionView>). La validación es inline por ser un filtro
 * simple de query string. La paginación es opcional y aditiva (E-02): si el cliente la pide (`per_page`,
 * `page` o `cursor`), se trocea la colección devuelta y se adjunta `meta`; si no, se devuelve completa.
 */
final class ListPromotionsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $statuses = array_map(
                static fn (PromotionStatus $status): string => $status->value,
                PromotionStatus::cases(),
            );

            /** @var array{status?: string|null} $filters */
            $filters = $request->validate([
                'status' => ['nullable', 'string', Rule::in($statuses)],
            ]);

            /** @var list<PromotionView> $views */
            $views = $this->queries->ask(new ListPromotions(
                status: $filters['status'] ?? null,
            ));

            $items = array_map(static fn (PromotionView $view): array => $view->toArray(), $views);

            // Paginación opcional (E-02): solo se activa si el cliente la pide; si no, lista completa.
            if (ApiPagination::requested($request)) {
                return new JsonResponse(ApiPagination::paginate($items, $request));
            }

            return $this->data($items);
        });
    }
}
