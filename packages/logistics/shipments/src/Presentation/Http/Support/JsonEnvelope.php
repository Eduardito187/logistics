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
     * Respuesta de error con el motivo SIEMPRE presente en `detail` y en `error.message` (decisión del
     * dueño), con un `code` estable derivado del estado cuando no se pasa uno, igual que el renderer global
     * RFC 7807. Así el app muestra el porqué leyendo cualquiera de los dos campos.
     */
    private function error(string $message, int $status, ?string $code = null): JsonResponse
    {
        $code ??= match (true) {
            $status === 400 => 'bad_request',
            $status === 401 => 'unauthenticated',
            $status === 403 => 'forbidden',
            $status === 404 => 'not_found',
            $status === 409 => 'conflict',
            $status === 422 => 'validation_failed',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'server_error',
            default => 'error',
        };

        return new JsonResponse([
            'type' => rtrim((string) config('api.problem_type_base_uri', '/problems'), '/').'/'.$code,
            'title' => ucfirst(str_replace('_', ' ', $code)),
            'status' => $status,
            'code' => $code,
            'detail' => $message,
            'error' => ['code' => $code, 'message' => $message],
        ], $status, ['Content-Type' => 'application/problem+json']);
    }
}
