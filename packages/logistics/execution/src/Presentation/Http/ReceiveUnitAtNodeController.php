<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Command\ReceiveUnitAtNode\ReceiveUnitAtNode;
use Logistics\Execution\Presentation\Http\Request\ReceiveUnitAtNodeRequest;

/**
 * POST /app — escanea la recepción de un bulto en un nodo destino (puerta app).
 *
 * Idempotente por la clave de idempotencia. La recepción del envío en ese nodo ya debe estar abierta.
 */
final class ReceiveUnitAtNodeController extends ExecutionController
{
    public function __invoke(ReceiveUnitAtNodeRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $this->commandBus->dispatch(new ReceiveUnitAtNode(
            shipmentId: (string) $data['shipment_id'],
            destinationNodeId: (string) $data['destination_node_id'],
            unitId: (string) $data['unit_id'],
            lat: (float) $data['lat'],
            lng: (float) $data['lng'],
            deviceId: $this->deviceId($request),
            userId: $this->userId($request),
            occurredAt: (string) $data['occurred_at'],
            idempotencyKey: $this->idempotencyKey($request),
        ));

        return $this->nodeReceptionResponse(
            (string) $data['shipment_id'],
            (string) $data['destination_node_id'],
        );
    }
}
