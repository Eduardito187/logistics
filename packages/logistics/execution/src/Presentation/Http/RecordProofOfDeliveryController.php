<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Command\RecordProofOfDelivery\RecordProofOfDelivery;
use Logistics\Execution\Presentation\Http\Request\ProofOfDeliveryRequest;

/**
 * POST /app — registra la prueba de entrega y cierra la entrega como entregada (puerta app).
 *
 * Idempotente por la clave de idempotencia. La entrega del envío ya debe existir (cargada o en tránsito).
 */
final class RecordProofOfDeliveryController extends ExecutionController
{
    public function __invoke(ProofOfDeliveryRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $photoUrl = $data['photo_url'] ?? null;
        $photoPath = $data['photo_path'] ?? null;

        $this->commandBus->dispatch(new RecordProofOfDelivery(
            shipmentId: (string) $data['shipment_id'],
            unitId: (string) $data['unit_id'],
            confirmation: (string) $data['confirmation'],
            photoUrl: is_string($photoUrl) ? $photoUrl : null,
            photoPath: is_string($photoPath) ? $photoPath : null,
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
