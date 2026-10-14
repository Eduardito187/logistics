<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\FleetOps\Application\Query\ListMaintenancePlans\ListMaintenancePlans;
use Logistics\FleetOps\Application\ReadModel\MaintenancePlanView;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista planes de mantenimiento preventivo con filtro opcional por vehículo (?vehicle_id=).
 */
final class ListMaintenancePlansController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $vehicleId = $request->query('vehicle_id');
            $vehicleId = is_string($vehicleId) && $vehicleId !== '' ? $vehicleId : null;

            /** @var list<MaintenancePlanView> $plans */
            $plans = $this->queries->ask(new ListMaintenancePlans(vehicleId: $vehicleId));

            return $this->data(
                array_map(static fn (MaintenancePlanView $view): array => $view->toArray(), $plans),
            );
        });
    }
}
