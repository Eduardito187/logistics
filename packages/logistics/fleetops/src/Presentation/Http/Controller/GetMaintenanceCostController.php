<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Query\GetMaintenanceCost\GetMaintenanceCost;
use Logistics\FleetOps\Application\ReadModel\MaintenanceCostView;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\GetMaintenanceCostRequest;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve el costo de mantenimiento de un vehículo en un rango de fechas (consulta GetMaintenanceCost).
 */
final class GetMaintenanceCostController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(GetMaintenanceCostRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var MaintenanceCostView $view */
            $view = $this->queries->ask(new GetMaintenanceCost(
                vehicleId: $request->string('vehicle_id')->toString(),
                from: $request->string('from')->toString(),
                to: $request->string('to')->toString(),
            ));

            return $this->data($view->toArray());
        });
    }
}
