<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /app — estado de la recepción de un envío en un nodo destino (puerta app).
 *
 * Devuelve lo esperado, lo recibido y las discrepancias (faltantes/sobrantes). 404 si todavía no se
 * abrió la recepción.
 */
final class GetNodeReceptionController extends ExecutionController
{
    public function __invoke(Request $request, string $shipmentId, string $nodeId): JsonResponse
    {
        $response = $this->nodeReceptionResponse($shipmentId, $nodeId);

        /** @var array{data: mixed} $payload */
        $payload = $response->getData(true);
        if ($payload['data'] === null) {
            return new JsonResponse(['data' => null], 404);
        }

        return $response;
    }
}
