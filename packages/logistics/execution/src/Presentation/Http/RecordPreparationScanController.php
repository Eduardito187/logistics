<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Command\RecordPreparationScan\RecordPreparationScan;
use Logistics\Execution\Presentation\Http\Request\PreparationScanRequest;

/**
 * POST /app — registra un escaneo de preparación de un producto (puerta app).
 *
 * Idempotente por la clave de idempotencia (header o cuerpo): el reenvío offline no duplica el hecho.
 */
final class RecordPreparationScanController extends ExecutionController
{
    public function __invoke(PreparationScanRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $this->commandBus->dispatch(new RecordPreparationScan(
            shipmentId: (string) $data['shipment_id'],
            vehicleId: (string) $data['vehicle_id'],
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
