<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Http\Support;

use Illuminate\Http\JsonResponse;

/**
 * Envelope JSON común de la capa HTTP del contexto.
 *
 * Mantiene una forma coherente entre endpoints: los datos siempre bajo `data` y los errores
 * siempre bajo `error.message`. Vive en Presentation; no conoce el dominio ni la persistencia.
 */
trait JsonEnvelope
{
    /**
     * Respuesta exitosa con los datos bajo `data`.
     */
    private function data(mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status);
    }

    /**
     * Respuesta de error con el mensaje bajo `error.message`.
     */
    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['message' => $message]], $status);
    }
}
