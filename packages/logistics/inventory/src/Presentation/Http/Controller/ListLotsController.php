<?php

declare(strict_types=1);

namespace Logistics\Inventory\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Inventory\Application\Query\ListLots\ListLots;
use Logistics\Inventory\Application\ReadModel\LotView;
use Logistics\Inventory\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista lotes con filtros opcionales por almacén / sku / dueño / modo, paginado.
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

            /** @var list<LotView> $lots */
            $lots = $this->queries->ask(new ListLots(
                warehouseId: $warehouseId !== '' ? $warehouseId : null,
                sku: $sku !== '' ? $sku : null,
                ownerPartyId: $ownerPartyId !== '' ? $ownerPartyId : null,
                fulfillmentMode: $fulfillmentMode !== '' ? $fulfillmentMode : null,
                limit: (int) $request->integer('limit', 100),
                offset: (int) $request->integer('offset', 0),
            ));

            return $this->data(array_map(static fn (LotView $v): array => $v->toArray(), $lots));
        });
    }
}
