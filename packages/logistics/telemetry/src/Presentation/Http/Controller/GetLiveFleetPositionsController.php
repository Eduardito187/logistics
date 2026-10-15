<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Telemetry\Application\Query\GetLiveFleetPositions\GetLiveFleetPositions;
use Logistics\Telemetry\Application\ReadModel\VehicleLiveView;
use Logistics\Telemetry\Presentation\Http\ApiController;

/**
 * Devuelve la posición en vivo de toda la flota (última lectura conocida de cada vehículo)
 * para pintar el mapa de la torre de control (consulta GetLiveFleetPositions).
 */
final class GetLiveFleetPositionsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(): JsonResponse
    {
        return $this->guard(function (): JsonResponse {
            /** @var list<VehicleLiveView> $positions */
            $positions = $this->queries->ask(new GetLiveFleetPositions);

            return $this->data(
                array_map(static fn (VehicleLiveView $view): array => $view->toArray(), $positions),
            );
        });
    }
}
