<?php

declare(strict_types=1);

namespace Commerce\Search\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Domain\Exception\DomainException;
use Throwable;

/**
 * Base de los controllers HTTP del contexto Search.
 *
 * Centraliza el envelope de respuesta JSON (coherente con el resto de la API) y la traducción de
 * excepciones de dominio a 422 (p.ej. límite fuera de rango o estado no permitido en `SearchCriteria`).
 * La Presentation solo habla con los buses CQRS: nunca toca Infrastructure ni Domain directamente, salvo
 * capturar su excepción base.
 */
abstract class ApiController
{
    /**
     * Respuesta de éxito con cuerpo de datos bajo la clave `data`.
     *
     * @param array<string, mixed>|list<mixed> $data
     * @param array<string, mixed>             $meta
     */
    protected function data(array $data, int $status = 200, array $meta = []): JsonResponse
    {
        $body = ['data' => $data];
        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        return new JsonResponse($body, $status);
    }

    /**
     * Respuesta de error con envelope `error` (código estable + mensaje legible).
     */
    protected function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    /**
     * Ejecuta la acción y traduce las excepciones de dominio a 422 (regla de negocio violada).
     *
     * @param callable(): JsonResponse $action
     */
    protected function guard(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (DomainException $e) {
            return $this->error('domain_rule_violation', $e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->error('internal_error', $e->getMessage(), 500);
        }
    }
}
