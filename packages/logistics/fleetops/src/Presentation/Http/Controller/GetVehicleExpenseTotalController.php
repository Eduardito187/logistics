<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Query\GetVehicleExpenseTotal\GetVehicleExpenseTotal;
use Logistics\FleetOps\Application\ReadModel\ExpenseTotalView;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\GetVehicleExpenseTotalRequest;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve el total gastado por un vehículo en un rango de fechas opcional (GetVehicleExpenseTotal).
 */
final class GetVehicleExpenseTotalController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(GetVehicleExpenseTotalRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var ExpenseTotalView $view */
            $view = $this->queries->ask(new GetVehicleExpenseTotal(
                vehicleId: $request->string('vehicle_id')->toString(),
                from: $request->filled('from') ? $request->string('from')->toString() : null,
                to: $request->filled('to') ? $request->string('to')->toString() : null,
            ));

            return $this->data($view->toArray());
        });
    }
}
