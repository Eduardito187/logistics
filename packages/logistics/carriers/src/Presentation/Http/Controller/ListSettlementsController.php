<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Carriers\Application\Query\ListSettlements\ListSettlements;
use Logistics\Carriers\Application\ReadModel\SettlementView;
use Logistics\Carriers\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Lista las liquidaciones como proyecciones de lectura, con filtros opcionales por transportista
 * (?carrier_id=) y por estado (?status=) (consulta ListSettlements).
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
            $carrierId = $request->query('carrier_id');
            $carrierId = is_string($carrierId) && $carrierId !== '' ? $carrierId : null;

            $status = $request->query('status');
            $status = is_string($status) && $status !== '' ? $status : null;

            /** @var Page<SettlementView> $page */
            $page = $this->queries->ask(new ListSettlements(
                carrierId: $carrierId,
                status: $status,
                limit: ApiPagination::limit($request),
                offset: ApiPagination::offset($request),
            ));

            $items = array_map(static fn (SettlementView $view): array => $view->toArray(), $page->items);

            return new JsonResponse(ApiPagination::envelope($items, $page->total, $request));
        });
    }
}
