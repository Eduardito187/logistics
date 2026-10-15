<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Query\GetVehicleCostSummary\GetVehicleCostSummary;
use Logistics\FleetOps\Application\ReadModel\VehicleCostSummaryView;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\GetVehicleCostSummaryRequest;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve el resumen de costos (TCO + costo por km) de un vehículo entre dos fechas
 * (consulta GetVehicleCostSummary): combustible + mantenimiento + gastos.
 */
final class GetVehicleCostSummaryController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(GetVehicleCostSummaryRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var VehicleCostSummaryView $view */
            $view = $this->queries->ask(new GetVehicleCostSummary(
                vehicleId: $request->string('vehicle_id')->toString(),
                from: $request->string('from')->toString(),
                to: $request->string('to')->toString(),
            ));

            return $this->data($view->toArray());
        });
    }
}
