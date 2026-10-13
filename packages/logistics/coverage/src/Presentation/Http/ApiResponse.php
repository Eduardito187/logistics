<?php

declare(strict_types=1);

namespace Logistics\Coverage\Presentation\Http;

use Illuminate\Http\JsonResponse;

/**
 * Envelope JSON coherente para las respuestas HTTP del contexto Cobertura.
 *
 * Toda respuesta de éxito va bajo la clave `data`; los errores de negocio (p. ej. punto sin
 * cobertura) van bajo `error` con un código estable que la app puede mapear. La validación de
 * entrada la resuelve Laravel con su 422 estándar, así que acá solo se modelan éxito y 404/409.
 */
final class ApiResponse
{
    /**
     * Respuesta de éxito con un recurso o colección bajo `data`.
     *
     * @param array<string, mixed>|array<int, mixed> $data
     */
    public static function ok(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status);
    }

    /**
     * Comando aceptado para procesarse (importaciones, escrituras asíncronas vía outbox).
     *
     * @param array<string, mixed> $data
     */
    public static function accepted(array $data = []): JsonResponse
    {
        return new JsonResponse(['data' => $data], 202);
    }

    /**
     * Error de negocio con código estable y mensaje legible.
     */
    public static function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
