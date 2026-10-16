<?php

declare(strict_types=1);

namespace Logistics\Inventory\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Inventory\Application\Query\GetStockOnHand\GetStockOnHand;
use Logistics\Inventory\Application\ReadModel\StockOnHandView;
use Logistics\Inventory\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve el stock on-hand de un almacén/sku agregado por dueño (propio vs. consignación de seller).
 */
final class GetStockOnHandController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $ownerPartyId = $request->string('owner_party_id')->toString();
            $fulfillmentMode = $request->string('fulfillment_mode')->toString();

            /** @var list<StockOnHandView> $rows */
            $rows = $this->queries->ask(new GetStockOnHand(
                warehouseId: $request->string('warehouse_id')->toString(),
                sku: $request->string('sku')->toString(),
                ownerPartyId: $ownerPartyId !== '' ? $ownerPartyId : null,
                fulfillmentMode: $fulfillmentMode !== '' ? $fulfillmentMode : null,
            ));

            return $this->data(array_map(static fn (StockOnHandView $v): array => $v->toArray(), $rows));
        });
    }
}
