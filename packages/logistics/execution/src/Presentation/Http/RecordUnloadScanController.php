<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Command\RecordUnloadScan\RecordUnloadScan;
use Logistics\Execution\Presentation\Http\Request\UnloadScanRequest;

/**
 * POST /app — registra la descarga guiada de un producto en una parada (puerta app).
 *
 * Idempotente por la clave de idempotencia. La entrega del envío ya debe existir (cargada o en tránsito).
 */
final class RecordUnloadScanController extends ExecutionController
{
    public function __invoke(UnloadScanRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $this->commandBus->dispatch(new RecordUnloadScan(
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
