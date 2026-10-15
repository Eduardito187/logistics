<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Command\ScheduleMaintenance\ScheduleMaintenance;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\ScheduleMaintenanceRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Agenda una orden de mantenimiento para un vehículo (comando ScheduleMaintenance).
 */
final class ScheduleMaintenanceController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(ScheduleMaintenanceRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $this->commands->dispatch(new ScheduleMaintenance(
                vehicleId: $request->string('vehicle_id')->toString(),
                type: $request->string('type')->toString(),
                odometerKm: $request->filled('odometer_km') ? $request->integer('odometer_km') : null,
                workshop: $request->filled('workshop') ? $request->string('workshop')->toString() : null,
                scheduledAt: $request->string('scheduled_at')->toString(),
                note: $request->filled('note') ? $request->string('note')->toString() : null,
            ));

            return $this->accepted(['vehicle_id' => $request->string('vehicle_id')->toString()]);
        });
    }
}
