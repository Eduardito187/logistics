<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Http\Concerns;

use Illuminate\Http\JsonResponse;

/**
 * Envelope de respuesta común a todos los controllers de Fleet.
 *
 * Los recursos van siempre bajo `data`; los listados agregan `meta.count`. Así el contrato con la
 * app Flutter y los canales es predecible y uniforme entre endpoints.
 */
trait RespondsWithEnvelope
{
    /**
     * Respuesta de un recurso o payload simple.
     *
     * @param array<string, mixed> $data
     */
    private function respondData(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status);
    }

    /**
     * Respuesta de un listado con conteo en `meta`.
     *
     * @param list<array<string, mixed>> $items
     */
    private function respondCollection(array $items): JsonResponse
    {
        return new JsonResponse([
            'data' => $items,
            'meta' => ['count' => count($items)],
        ], 200);
    }

    /**
     * Acuse de un comando aceptado y aplicado (los comandos del dominio devuelven void).
     *
     * @param array<string, mixed> $data
     */
    private function respondAccepted(array $data = [], int $status = 201): JsonResponse
    {
        return new JsonResponse(['data' => ['status' => 'ok'] + $data], $status);
    }
}
