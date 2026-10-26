<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Command\RecordLoadScan\RecordLoadScan;
use Logistics\Execution\Presentation\Http\Request\LoadScanRequest;

/**
 * POST /app — registra un escaneo de carga validada del producto al vehículo (puerta app).
 *
 * Idempotente por la clave de idempotencia. La entrega del envío ya debe existir (preparada).
 */
final class RecordLoadScanController extends ExecutionController
{
    public function __invoke(LoadScanRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $this->commandBus->dispatch(new RecordLoadScan(
            shipmentId: (string) $data['shipment_id'],
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
