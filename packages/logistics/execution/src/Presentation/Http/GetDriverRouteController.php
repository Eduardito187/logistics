<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Execution\Application\Query\GetDriverRoute\GetDriverRoute;
use Logistics\Execution\Application\ReadModel\DriverRouteView;

/**
 * GET /app/vehicles/{vehicleId}/route — "mi ruta" del chofer: la secuencia ordenada de paradas con lo
 * que baja en cada una, la parada actual y cuántas quedan. Con ?date=YYYY-MM-DD, la de ese día; sin
 * fecha, la ruta abierta vigente del camión.
 */
final class GetDriverRouteController extends ExecutionController
{
    public function __invoke(Request $request, string $vehicleId): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['sometimes', 'nullable', 'string', 'date_format:Y-m-d'],
        ]);

        $date = isset($validated['date']) && is_string($validated['date']) ? $validated['date'] : null;

        /** @var DriverRouteView|null $view */
        $view = $this->queryBus->ask(new GetDriverRoute($vehicleId, $date));

        if ($view === null) {
            return new JsonResponse([
                'error' => ['code' => 'not_found', 'message' => "no route for vehicle {$vehicleId}"],
            ], 404);
        }

        return new JsonResponse(['data' => $view->toArray()]);
    }
}
