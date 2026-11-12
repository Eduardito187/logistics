<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Command\ReceiveUnitAtNode\ReceiveUnitAtNode;
use Logistics\Execution\Presentation\Http\Request\ReceiveUnitAtNodeRequest;

/**
 * POST /app — escanea la recepción de un producto en un nodo destino (puerta app).
 *
 * Idempotente por la clave de idempotencia. La recepción del envío en ese nodo ya debe estar abierta.
 * GPS opcional: `lat`/`lng` planos o `location: {lat, lng, accuracy_m, captured_at}`; sin GPS (o 0,0) el
 * escaneo se registra sin posición.
 */
final class ReceiveUnitAtNodeController extends ExecutionController
{
    public function __invoke(ReceiveUnitAtNodeRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        /** @var array<string, mixed> $location */
        $location = isset($data['location']) && is_array($data['location']) ? $data['location'] : [];

        // Los campos planos mandan; si no vienen, se usa el objeto `location`.
        $lat = self::floatOrNull($data['lat'] ?? null) ?? self::floatOrNull($location['lat'] ?? null);
        $lng = self::floatOrNull($data['lng'] ?? null) ?? self::floatOrNull($location['lng'] ?? null);
        $capturedAt = $location['captured_at'] ?? null;

        $this->commandBus->dispatch(new ReceiveUnitAtNode(
            shipmentId: (string) $data['shipment_id'],
            destinationNodeId: (string) $data['destination_node_id'],
            unitId: (string) $data['unit_id'],
            lat: $lat,
            lng: $lng,
            deviceId: $this->deviceId($request),
            userId: $this->userId($request),
            occurredAt: (string) $data['occurred_at'],
            idempotencyKey: $this->idempotencyKey($request),
            accuracyM: self::floatOrNull($location['accuracy_m'] ?? null),
            locationCapturedAt: is_string($capturedAt) && $capturedAt !== '' ? $capturedAt : null,
        ));

        return $this->nodeReceptionResponse(
            (string) $data['shipment_id'],
            (string) $data['destination_node_id'],
        );
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
