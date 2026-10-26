<?php

declare(strict_types=1);

namespace Logistics\Returns\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Returns\Application\Query\ListReturns\ListReturns;
use Logistics\Returns\Application\ReadModel\ReturnView;
use Logistics\Returns\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Lista devoluciones (GET /v1/returns), con filtros opcionales por seller y estado.
 *
 * Tabla de crecimiento ilimitado: paginación OBLIGATORIA (per_page por defecto 50), con recorte en SQL
 * (LIMIT/OFFSET + COUNT(*) aparte).
 */
final class ListReturnsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $sellerId = $request->query('seller_id');
            $status = $request->query('status');

            /** @var Page<ReturnView> $page */
            $page = $this->queries->ask(new ListReturns(
                sellerId: is_string($sellerId) && $sellerId !== '' ? $sellerId : null,
                status: is_string($status) && $status !== '' ? $status : null,
                limit: ApiPagination::limit($request),
                offset: ApiPagination::offset($request),
            ));

            $items = array_map(static fn (ReturnView $view): array => $view->toArray(), $page->items);

            return new JsonResponse(ApiPagination::envelope($items, $page->total, $request));
        });
    }
}
