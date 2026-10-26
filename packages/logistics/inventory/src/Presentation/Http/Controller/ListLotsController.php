<?php

declare(strict_types=1);

namespace Logistics\Inventory\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Inventory\Application\Query\ListLots\ListLots;
use Logistics\Inventory\Application\ReadModel\LotView;
use Logistics\Inventory\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Lista lotes con filtros opcionales por almacén / sku / dueño / modo, paginado.
 *
 * Tabla de crecimiento ilimitado: paginación OBLIGATORIA (per_page por defecto 50), con recorte en SQL
 * (LIMIT/OFFSET + COUNT(*) aparte). Acepta `page`/`per_page` (o `cursor`); traduce a LIMIT/OFFSET.
 */
final class ListLotsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $warehouseId = $request->string('warehouse_id')->toString();
            $sku = $request->string('sku')->toString();
            $ownerPartyId = $request->string('owner_party_id')->toString();
            $fulfillmentMode = $request->string('fulfillment_mode')->toString();

            /** @var Page<LotView> $page */
            $page = $this->queries->ask(new ListLots(
                warehouseId: $warehouseId !== '' ? $warehouseId : null,
                sku: $sku !== '' ? $sku : null,
                ownerPartyId: $ownerPartyId !== '' ? $ownerPartyId : null,
                fulfillmentMode: $fulfillmentMode !== '' ? $fulfillmentMode : null,
                limit: ApiPagination::limit($request),
                offset: ApiPagination::offset($request),
            ));

            $items = array_map(static fn (LotView $v): array => $v->toArray(), $page->items);

            return new JsonResponse(ApiPagination::envelope($items, $page->total, $request));
        });
    }
}
