<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Command\DefineMaintenancePlan\DefineMaintenancePlan;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\DefineMaintenancePlanRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Define un plan de mantenimiento preventivo para un vehículo (comando DefineMaintenancePlan).
 *
 * El intervalo puede ser por km, por días o ambos; el dominio exige que al menos uno esté presente.
 */
final class DefineMaintenancePlanController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(DefineMaintenancePlanRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $this->commands->dispatch(new DefineMaintenancePlan(
                vehicleId: $request->string('vehicle_id')->toString(),
                serviceKind: $request->string('service_kind')->toString(),
                intervalKm: $request->filled('interval_km') ? $request->integer('interval_km') : null,
                intervalDays: $request->filled('interval_days') ? $request->integer('interval_days') : null,
            ));

            return $this->accepted(['vehicle_id' => $request->string('vehicle_id')->toString()]);
        });
    }
}
