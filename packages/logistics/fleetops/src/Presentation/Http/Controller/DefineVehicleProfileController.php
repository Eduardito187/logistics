<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Command\DefineVehicleProfile\DefineVehicleProfile;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\DefineVehicleProfileRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Define (o re-define) el perfil técnico de un vehículo (comando DefineVehicleProfile). Upsert por
 * `vehicle_id`: el mismo endpoint crea y actualiza.
 */
final class DefineVehicleProfileController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(DefineVehicleProfileRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $this->commands->dispatch(new DefineVehicleProfile(
                vehicleId: $request->string('vehicle_id')->toString(),
                brand: $request->filled('brand') ? $request->string('brand')->toString() : null,
                model: $request->filled('model') ? $request->string('model')->toString() : null,
                year: $request->filled('year') ? $request->integer('year') : null,
                fuelType: $request->filled('fuel_type') ? $request->string('fuel_type')->toString() : null,
                tankLiters: $request->filled('tank_liters') ? $request->float('tank_liters') : null,
                vin: $request->filled('vin') ? $request->string('vin')->toString() : null,
                color: $request->filled('color') ? $request->string('color')->toString() : null,
            ));

            return $this->accepted(['vehicle_id' => $request->string('vehicle_id')->toString()]);
        });
    }
}
