<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Command\UpdateVehicleOdometer\UpdateVehicleOdometer;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\UpdateVehicleOdometerRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Fija el odómetro vigente de un vehículo en su perfil (comando UpdateVehicleOdometer). Solo avanza:
 * el dominio rechaza un valor menor al actual. El id del vehículo llega por la ruta.
 */
final class UpdateVehicleOdometerController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(UpdateVehicleOdometerRequest $request, string $vehicleId): JsonResponse
    {
        return $this->guard(function () use ($request, $vehicleId): JsonResponse {
            $this->commands->dispatch(new UpdateVehicleOdometer(
                vehicleId: $vehicleId,
                km: $request->integer('km'),
            ));

            return $this->accepted(['vehicle_id' => $vehicleId]);
        });
    }
}
