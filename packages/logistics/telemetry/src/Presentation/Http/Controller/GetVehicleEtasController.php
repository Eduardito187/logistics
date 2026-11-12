<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Telemetry\Application\Query\GetVehicleEtas\GetVehicleEtas;
use Logistics\Telemetry\Application\ReadModel\VehicleEtaView;
use Logistics\Telemetry\Presentation\Http\ApiController;

/**
 * ETA dinámica de cada parada restante de la ruta del día de un vehículo, recalculada desde su última
 * posición (consulta GetVehicleEtas). Sin tráfico en vivo (`traffic_aware: false`).
 */
final class GetVehicleEtasController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(string $vehicleId): JsonResponse
    {
        return $this->guard(function () use ($vehicleId): JsonResponse {
            /** @var VehicleEtaView $view */
            $view = $this->queries->ask(new GetVehicleEtas($vehicleId));

            return $this->data($view->toArray());
        });
    }
}
