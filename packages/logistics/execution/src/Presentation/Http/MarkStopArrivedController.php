<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Query\GetRouteStop\GetRouteStop;
use Logistics\Execution\Application\ReadModel\RouteStopView;
use Logistics\Execution\Presentation\Http\Request\StopArrivalRequest;
use Logistics\Execution\Presentation\Http\Support\ScanCommandFactory;

/**
 * POST /app/deliveries/{shipmentId}/arrived — el camión llegó a la parada (geocerca o manual; puerta app).
 *
 * Persiste `arrived_at` de la parada (la PRIMERA llegada gana) y emite execution.stop_arrived (base de los
 * KPIs de tiempo en parada). Idempotente: header Idempotency-Key + regla de dominio. Responde la parada
 * (DriverRouteStop) con su `arrived_at`. Envío que no es parada de ninguna ruta → 422.
 */
final class MarkStopArrivedController extends ExecutionController
{
    public function __invoke(StopArrivalRequest $request, string $shipmentId): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $this->commandBus->dispatch(ScanCommandFactory::arrival($data, $shipmentId));

        /** @var RouteStopView|null $stop */
        $stop = $this->queryBus->ask(new GetRouteStop($shipmentId));

        return new JsonResponse(['data' => $stop?->toArray()]);
    }
}
