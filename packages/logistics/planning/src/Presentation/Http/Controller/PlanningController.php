<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;

/**
 * Base de los controllers HTTP del contexto de planificación.
 *
 * Centraliza el envelope JSON para que todas las respuestas del contexto tengan la misma forma:
 *   - éxito con un recurso   → { "data": { ... } }
 *   - éxito con una colección → { "data": [ ... ], "meta": { ... } }
 *   - error                   → { "error": { "code": "...", "message": "..." } }
 *
 * Los controllers hablan SOLO con los buses CQRS (CommandBus/QueryBus); nunca tocan Infrastructure ni
 * Domain directo. Por eso acá no hay nada de persistencia ni de agregados.
 */
abstract class PlanningController
{
    /**
     * Respuesta de éxito con un único recurso.
     *
     * @param array<string, mixed> $data
     */
    protected function resource(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status);
    }

    /**
     * Respuesta de éxito con una colección y, opcionalmente, metadatos del listado.
     *
     * @param list<array<string, mixed>> $data
     * @param array<string, mixed>       $meta
     */
    protected function collection(array $data, array $meta = []): JsonResponse
    {
        return new JsonResponse(['data' => $data, 'meta' => $meta], 200);
    }

    /**
     * Respuesta de recurso inexistente con el envelope de error del contexto.
     */
    protected function notFound(string $message): JsonResponse
    {
        return new JsonResponse([
            'error' => ['code' => 'not_found', 'message' => $message],
        ], 404);
    }
}
