<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;

/**
 * GET /app — estado actual de la entrega de un envío, con sus escaneos (puerta app).
 *
 * Devuelve 404 si la entrega todavía no se abrió (ningún escaneo registrado aún).
 */
final class GetDeliveryStateController extends ExecutionController
{
    public function __invoke(string $shipmentId): JsonResponse
    {
        $response = $this->deliveryStateResponse($shipmentId);

        /** @var array{data: mixed} $payload */
        $payload = $response->getData(true);

        if ($payload['data'] === null) {
            return new JsonResponse([
                'error' => [
                    'code' => 'delivery_not_found',
                    'message' => 'No delivery has been opened for this shipment yet.',
                ],
            ], 404);
        }

        return $response;
    }
}
