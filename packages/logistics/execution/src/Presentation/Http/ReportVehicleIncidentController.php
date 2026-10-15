<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Command\ReportVehicleIncident\ReportVehicleIncident;
use Logistics\Execution\Application\Query\GetDriverRoute\GetDriverRoute;
use Logistics\Execution\Application\ReadModel\DriverRouteView;
use Logistics\Execution\Presentation\Http\Request\ReportIncidentRequest;

/**
 * POST /app/vehicles/{vehicleId}/incidents — el chofer reporta un incidente (avería o accidente) con su
 * camión (puerta app). Despacha ReportVehicleIncident, que deja constancia inmutable del hecho y aplica
 * la reacción automática: reprograma SOLO las paradas pendientes (MAJOR → día siguiente; MINOR → empuja
 * ETAs), deja el camión fuera de servicio en flota y avisa a clientes y supervisor, todo por el outbox.
 *
 * Idempotente por `Idempotency-Key`. Devuelve la ruta del chofer ya actualizada para que la app offline
 * reconcilie su vista local en una sola llamada.
 */
final class ReportVehicleIncidentController extends ExecutionController
{
    public function __invoke(ReportIncidentRequest $request, string $vehicleId): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $routeId = isset($data['route_id']) && is_string($data['route_id']) && $data['route_id'] !== ''
            ? $data['route_id']
            : null;

        $note = isset($data['note']) && is_string($data['note']) && trim($data['note']) !== ''
            ? $data['note']
            : null;

        // La clave de idempotencia vacía se normaliza a null: la columna es única y no debe guardar ''.
        $idempotencyKey = $this->idempotencyKey($request);
        $idempotencyKey = $idempotencyKey !== '' ? $idempotencyKey : null;

        $this->commandBus->dispatch(new ReportVehicleIncident(
            vehicleId: $vehicleId,
            severity: (string) $data['severity'],
            type: (string) $data['type'],
            routeId: $routeId,
            note: $note,
            lat: isset($data['lat']) ? (float) $data['lat'] : null,
            lng: isset($data['lng']) ? (float) $data['lng'] : null,
            deviceId: $this->deviceId($request),
            userId: $this->userId($request),
            occurredAt: isset($data['occurred_at']) && is_string($data['occurred_at']) ? $data['occurred_at'] : null,
            idempotencyKey: $idempotencyKey,
        ));

        /** @var DriverRouteView|null $view */
        $view = $this->queryBus->ask(new GetDriverRoute($vehicleId));

        return new JsonResponse(['data' => $view?->toArray()], 202);
    }
}
