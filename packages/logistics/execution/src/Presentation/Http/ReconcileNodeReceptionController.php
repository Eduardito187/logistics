<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Command\ReconcileNodeReception\ReconcileNodeReception;
use Logistics\Execution\Presentation\Http\Request\ReconcileNodeReceptionRequest;

/**
 * POST /app — cierra (concilia) la recepción de un envío en un nodo destino (puerta app).
 *
 * Compara lo recibido contra lo esperado y graba las discrepancias (faltantes/sobrantes). Idempotente:
 * reconciliar dos veces no re-emite el evento.
 */
final class ReconcileNodeReceptionController extends ExecutionController
{
    public function __invoke(ReconcileNodeReceptionRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $this->commandBus->dispatch(new ReconcileNodeReception(
            shipmentId: (string) $data['shipment_id'],
            destinationNodeId: (string) $data['destination_node_id'],
            deviceId: $this->deviceId($request),
            userId: $this->userId($request),
            occurredAt: (string) $data['occurred_at'],
        ));

        return $this->nodeReceptionResponse(
            (string) $data['shipment_id'],
            (string) $data['destination_node_id'],
        );
    }
}
