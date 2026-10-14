<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Query\GetVehicleProfile\GetVehicleProfile;
use Logistics\FleetOps\Application\ReadModel\VehicleProfileView;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve el perfil técnico de un vehículo por su id (consulta GetVehicleProfile). 404 si no existe.
 */
final class GetVehicleProfileController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(string $vehicleId): JsonResponse
    {
        return $this->guard(function () use ($vehicleId): JsonResponse {
            /** @var VehicleProfileView|null $profile */
            $profile = $this->queries->ask(new GetVehicleProfile(vehicleId: $vehicleId));

            if ($profile === null) {
                return $this->error('vehicle_profile_not_found', 'No vehicle profile found for the given vehicle.', 404);
            }

            return $this->data($profile->toArray());
        });
    }
}
