<?php

declare(strict_types=1);

namespace Logistics\Settlement\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Settlement\Application\Query\ListSettlements\ListSettlements;
use Logistics\Settlement\Application\ReadModel\SettlementView;
use Logistics\Settlement\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Lista las liquidaciones de sellers como proyecciones de lectura, con filtros opcionales por seller
 * (?seller_id=), estado (?status=) y periodo (?period_from=&period_to=) (consulta ListSettlements).
 *
 * Tabla de crecimiento ilimitado: paginación OBLIGATORIA (per_page por defecto 50), con recorte en SQL
 * (LIMIT/OFFSET + COUNT(*) aparte).
 */
final class ListSettlementsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var Page<SettlementView> $page */
            $page = $this->queries->ask(new ListSettlements(
                sellerId: $this->nullableQuery($request, 'seller_id'),
                status: $this->nullableQuery($request, 'status'),
                periodFrom: $this->nullableQuery($request, 'period_from'),
                periodTo: $this->nullableQuery($request, 'period_to'),
                limit: ApiPagination::limit($request),
                offset: ApiPagination::offset($request),
            ));

            $items = array_map(static fn (SettlementView $view): array => $view->toArray(), $page->items);

            return new JsonResponse(ApiPagination::envelope($items, $page->total, $request));
        });
    }

    private function nullableQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
