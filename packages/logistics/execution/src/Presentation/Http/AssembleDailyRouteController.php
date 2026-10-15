<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Query\GetDriverRoute\GetDriverRoute;
use Logistics\Execution\Application\ReadModel\DriverRouteView;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Presentation\Http\Request\AssembleRouteRequest;

/**
 * POST /app/vehicles/{vehicleId}/route — arma la ruta del día del chofer a partir de las paradas del
 * plan publicado (secuencia + foto legible del destino). Despacha el comando AssembleDailyRoute y
 * devuelve la ruta armada releyéndola por la consulta.
 */
final class AssembleDailyRouteController extends ExecutionController
{
    public function __invoke(AssembleRouteRequest $request, string $vehicleId): JsonResponse
    {
        $command = $request->toCommand($vehicleId);

        try {
            $this->commandBus->dispatch($command);
        } catch (ExecutionException $exception) {
            return new JsonResponse([
                'error' => ['code' => 'invalid_route', 'message' => $exception->getMessage()],
            ], 422);
        }

        /** @var DriverRouteView|null $view */
        $view = $this->queryBus->ask(new GetDriverRoute($vehicleId, $command->routeDate));

        return new JsonResponse(['data' => $view?->toArray()], 201);
    }
}
