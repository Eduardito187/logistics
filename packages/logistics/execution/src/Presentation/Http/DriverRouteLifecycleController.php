<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Command\CloseDriverRoute\CloseDriverRoute;
use Logistics\Execution\Application\Command\StartDriverRoute\StartDriverRoute;
use Logistics\Execution\Application\Query\GetDriverRouteLifecycle\GetDriverRouteLifecycle;
use Logistics\Execution\Application\ReadModel\DriverRouteView;
use Logistics\Execution\Presentation\Http\Request\RouteLifecycleRequest;

/**
 * POST /app/vehicles/{vehicleId}/route/start y /route/close — abrir y cerrar la ruta del día (puerta app).
 *
 * Idempotentes (la primera llamada gana; el reintento devuelve el mismo estado) y offline-first
 * (`occurred_at` lo fija la app). Responden el ciclo de vida: `status`, `started_at`, `closed_at`, los
 * odómetros y `pending_stops` (el cierre NO exige todas las paradas entregadas: las pendientes quedan).
 * Sin ruta para el camión → 422 business_rule_violation.
 */
final class DriverRouteLifecycleController extends ExecutionController
{
    public function start(RouteLifecycleRequest $request, string $vehicleId): JsonResponse
    {
        $input = $this->input($request);

        $this->commandBus->dispatch(new StartDriverRoute(
            $vehicleId,
            $input['occurred_at'],
            $input['route_date'],
            $input['odometer_km'],
            $input['lat'],
            $input['lng'],
        ));

        return $this->lifecycleResponse($vehicleId, $input['route_date'], $input['occurred_at']);
    }

    public function close(RouteLifecycleRequest $request, string $vehicleId): JsonResponse
    {
        $input = $this->input($request);

        $this->commandBus->dispatch(new CloseDriverRoute(
            $vehicleId,
            $input['occurred_at'],
            $input['route_date'],
            $input['odometer_km'],
            $input['lat'],
            $input['lng'],
        ));

        return $this->lifecycleResponse($vehicleId, $input['route_date'], $input['occurred_at']);
    }

    /**
     * @return array{occurred_at: string, route_date: string|null, odometer_km: float|null, lat: float|null, lng: float|null}
     */
    private function input(RouteLifecycleRequest $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $routeDate = $data['route_date'] ?? null;

        return [
            'occurred_at' => (string) $data['occurred_at'],
            'route_date' => is_string($routeDate) && $routeDate !== '' ? $routeDate : null,
            'odometer_km' => isset($data['odometer_km']) && is_numeric($data['odometer_km']) ? (float) $data['odometer_km'] : null,
            'lat' => isset($data['lat']) && is_numeric($data['lat']) ? (float) $data['lat'] : null,
            'lng' => isset($data['lng']) && is_numeric($data['lng']) ? (float) $data['lng'] : null,
        ];
    }

    private function lifecycleResponse(string $vehicleId, ?string $routeDate, string $occurredAt): JsonResponse
    {
        /** @var DriverRouteView|null $view */
        $view = $this->queryBus->ask(new GetDriverRouteLifecycle($vehicleId, $routeDate, $occurredAt));

        return new JsonResponse(['data' => $view?->lifecycleArray()]);
    }
}
