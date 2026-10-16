<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Command\ReportDeliveryIssue\ReportDeliveryIssue;
use Logistics\Execution\Presentation\Http\Request\ReportDeliveryIssueRequest;

/**
 * POST /app/deliveries/{shipmentId}/issues — el chofer reporta un inconveniente de una entrega EN CURSO
 * (puerta app). No cierra la entrega: despacha ReportDeliveryIssue, que deja constancia inmutable del
 * hecho y avisa a la torre de control y a las notificaciones por el outbox.
 *
 * Idempotente por `Idempotency-Key` (header o cuerpo): el reenvío del mismo reporte de la app offline no
 * duplica el hecho. Devuelve 202 Accepted con el eco del inconveniente registrado.
 */
final class ReportDeliveryIssueController extends ExecutionController
{
    public function __invoke(ReportDeliveryIssueRequest $request, string $shipmentId): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $note = isset($data['note']) && is_string($data['note']) && trim($data['note']) !== ''
            ? $data['note']
            : null;

        $photoPath = isset($data['photo_path']) && is_string($data['photo_path']) && trim($data['photo_path']) !== ''
            ? $data['photo_path']
            : null;

        // La clave de idempotencia vacía se normaliza a null: la columna es única y no debe guardar ''.
        $idempotencyKey = $this->idempotencyKey($request);
        $idempotencyKey = $idempotencyKey !== '' ? $idempotencyKey : null;

        $type = (string) $data['type'];

        $this->commandBus->dispatch(new ReportDeliveryIssue(
            shipmentId: $shipmentId,
            type: $type,
            note: $note,
            lat: isset($data['lat']) ? (float) $data['lat'] : null,
            lng: isset($data['lng']) ? (float) $data['lng'] : null,
            photoPath: $photoPath,
            deviceId: $this->deviceId($request),
            userId: $this->userId($request),
            occurredAt: isset($data['occurred_at']) && is_string($data['occurred_at']) ? $data['occurred_at'] : null,
            idempotencyKey: $idempotencyKey,
        ));

        return new JsonResponse([
            'data' => [
                'shipment_id' => $shipmentId,
                'type' => $type,
                'status' => 'REPORTED',
            ],
        ], 202);
    }
}
