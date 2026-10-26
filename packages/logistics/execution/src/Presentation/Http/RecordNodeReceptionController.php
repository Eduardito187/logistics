<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Command\RecordNodeReception\RecordNodeReception;
use Logistics\Execution\Presentation\Http\Request\NodeReceptionRequest;

/**
 * POST /app — registra la recepción de un producto en un nodo logístico (puerta app).
 *
 * Idempotente por la clave de idempotencia. La entrega del envío ya debe existir (cargada o en tránsito).
 */
final class RecordNodeReceptionController extends ExecutionController
{
    public function __invoke(NodeReceptionRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $this->commandBus->dispatch(new RecordNodeReception(
            shipmentId: (string) $data['shipment_id'],
            nodeId: (string) $data['node_id'],
            unitId: (string) $data['unit_id'],
            lat: (float) $data['lat'],
            lng: (float) $data['lng'],
            deviceId: $this->deviceId($request),
            userId: $this->userId($request),
            occurredAt: (string) $data['occurred_at'],
            idempotencyKey: $this->idempotencyKey($request),
        ));

        return $this->deliveryStateResponse((string) $data['shipment_id']);
    }
}
