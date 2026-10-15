<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Query\GetFuelConsumption\GetFuelConsumption;
use Logistics\FleetOps\Application\ReadModel\FuelConsumptionView;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\GetFuelConsumptionRequest;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve el consumo de combustible de un vehículo en un rango de fechas (consulta GetFuelConsumption).
 */
final class GetFuelConsumptionController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(GetFuelConsumptionRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var FuelConsumptionView $view */
            $view = $this->queries->ask(new GetFuelConsumption(
                vehicleId: $request->string('vehicle_id')->toString(),
                from: $request->string('from')->toString(),
                to: $request->string('to')->toString(),
            ));

            return $this->data($view->toArray());
        });
    }
}
