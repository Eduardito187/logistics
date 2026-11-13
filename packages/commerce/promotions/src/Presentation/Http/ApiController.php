<?php

declare(strict_types=1);

namespace Commerce\Promotions\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Domain\Exception\DomainException;
use Throwable;

/**
 * Base de los controllers HTTP del contexto Promotions.
 *
 * Centraliza el envelope de respuesta JSON (coherente con el resto de la API) y la traducción de
 * excepciones de dominio a respuestas 422. La Presentation solo habla con los buses CQRS: estos
 * controllers nunca tocan Infrastructure ni Domain directamente, salvo capturar su excepción base.
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
     * Respuesta de comando aceptado (escritura asíncrona vía outbox): no devuelve recurso, solo acuse.
     *
     * @param array<string, mixed> $echo datos aceptados que se devuelven como acuse
     */
    protected function accepted(array $echo = []): JsonResponse
    {
        return new JsonResponse(['data' => ['accepted' => true] + $echo], 202);
    }

    /**
     * Respuesta de error con envelope `error` (código estable + mensaje legible + detalles opcionales).
     *
     * @param array<string, mixed> $details
     */
    protected function error(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }

        // Body unificado (decisión del dueño): el motivo viaja SIEMPRE en `detail` y en `error.message`,
        // con `code` estable, igual que el renderer global RFC 7807; ninguna API responde distinto a otra.
        return new JsonResponse([
            'type' => rtrim((string) config('api.problem_type_base_uri', '/problems'), '/').'/'.$code,
            'title' => ucfirst(str_replace('_', ' ', $code)),
            'status' => $status,
            'code' => $code,
            'detail' => $message,
            'error' => $error,
        ], $status, ['Content-Type' => 'application/problem+json']);
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
