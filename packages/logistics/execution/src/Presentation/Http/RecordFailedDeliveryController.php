<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Command\RecordFailedDelivery\RecordFailedDelivery;
use Logistics\Execution\Presentation\Http\Request\FailedDeliveryRequest;

/**
 * POST /app — registra un intento de entrega fallido con su motivo y cierra la entrega (puerta app).
 *
 * Idempotente por la clave de idempotencia. La entrega del envío ya debe existir (cargada o en tránsito).
 */
final class RecordFailedDeliveryController extends ExecutionController
{
    public function __invoke(FailedDeliveryRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $this->commandBus->dispatch(new RecordFailedDelivery(
            shipmentId: (string) $data['shipment_id'],
            unitId: (string) $data['unit_id'],
            reason: (string) $data['reason'],
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
