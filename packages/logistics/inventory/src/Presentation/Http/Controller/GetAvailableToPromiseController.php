<?php

declare(strict_types=1);

namespace Logistics\Inventory\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Inventory\Application\Query\GetAvailableToPromise\GetAvailableToPromise;
use Logistics\Inventory\Application\ReadModel\AvailableToPromiseView;
use Logistics\Inventory\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve el Available-to-Promise de un almacén/sku (stock disponible, no reservado), opcionalmente por
 * dueño. Lo consume el motor de promesa y el portal del seller.
 */
final class GetAvailableToPromiseController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $ownerPartyId = $request->string('owner_party_id')->toString();

            /** @var AvailableToPromiseView $view */
            $view = $this->queries->ask(new GetAvailableToPromise(
                warehouseId: $request->string('warehouse_id')->toString(),
                sku: $request->string('sku')->toString(),
                ownerPartyId: $ownerPartyId !== '' ? $ownerPartyId : null,
            ));

            return $this->data($view->toArray());
        });
    }
}
