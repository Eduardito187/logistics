<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Query\GetVehicleUtilization\GetVehicleUtilization;
use Logistics\FleetOps\Application\ReadModel\UtilizationView;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\GetVehicleUtilizationRequest;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve la utilización de un vehículo en un rango de fechas (consulta GetVehicleUtilization).
 */
final class GetVehicleUtilizationController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(GetVehicleUtilizationRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var UtilizationView $view */
            $view = $this->queries->ask(new GetVehicleUtilization(
                vehicleId: $request->string('vehicle_id')->toString(),
                from: $request->string('from')->toString(),
                to: $request->string('to')->toString(),
            ));

            return $this->data($view->toArray());
        });
    }
}
