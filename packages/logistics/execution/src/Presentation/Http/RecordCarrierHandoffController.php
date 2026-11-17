<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Application\Command\RecordCarrierHandoff\RecordCarrierHandoff;
use Logistics\Execution\Presentation\Http\Request\CarrierHandoffRequest;

/**
 * POST /app/deliveries/{shipmentId}/carrier-handoff — ENTREGA A LA TRANSPORTADORA (handoff, puerta app).
 *
 * NUESTRO lado dejó la carga en el almacén de la transportadora (nodo `carrier_hub`). Decisión del dueño: el
 * "entregado a transportadora" se marca con ESTE escaneo propio, NO cuando el tercero arranca. Cierra el
 * tramo de flota propia (`execution.shipment_leg_closed`) y declara el pase al tercero
 * (`execution.shipment_handed_to_carrier`); la entrega NO pasa a Delivered (el envío sigue).
 *
 * Idempotente por la clave de idempotencia. La entrega del envío ya debe existir (abierta). El actor
 * (dispositivo + usuario) sale del token, nunca del cuerpo.
 */
final class RecordCarrierHandoffController extends ExecutionController
{
    public function __invoke(CarrierHandoffRequest $request, string $shipmentId): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $occurredAt = isset($data['occurred_at']) && trim((string) $data['occurred_at']) !== ''
            ? (string) $data['occurred_at']
            : now()->toAtomString();
        $carrierId = isset($data['carrier_id']) && trim((string) $data['carrier_id']) !== ''
            ? trim((string) $data['carrier_id'])
            : null;

        $this->commandBus->dispatch(new RecordCarrierHandoff(
            shipmentId: $shipmentId,
            carrierHubNodeId: (string) $data['carrier_hub_node_id'],
            deviceId: $this->deviceId($request),
            userId: $this->userId($request),
            occurredAt: $occurredAt,
            idempotencyKey: $this->idempotencyKey($request),
            carrierId: $carrierId,
            lat: isset($data['lat']) && is_numeric($data['lat']) ? (float) $data['lat'] : null,
            lng: isset($data['lng']) && is_numeric($data['lng']) ? (float) $data['lng'] : null,
        ));

        return $this->deliveryStateResponse($shipmentId);
    }
}
