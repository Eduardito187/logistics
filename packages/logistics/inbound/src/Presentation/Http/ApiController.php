<?php

declare(strict_types=1);

namespace Logistics\Inbound\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Domain\Exception\DomainException;
use Throwable;

/**
 * Base de los controllers HTTP del contexto Inbound.
 *
 * Centraliza el envelope de respuesta JSON y la traducción de excepciones de dominio a 422. La
 * Presentation solo habla con los buses CQRS: nunca toca Infrastructure ni Domain directo, salvo capturar
 * su excepción base.
 */
abstract class ApiController
{
    /**
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
     * Respuesta de comando aceptado (escritura asíncrona vía outbox): solo acuse.
     *
     * @param array<string, mixed> $echo
     */
    protected function accepted(array $echo = []): JsonResponse
    {
        return new JsonResponse(['data' => ['accepted' => true] + $echo], 202);
    }

    /**
     * @param array<string, mixed> $details
     */
    protected function error(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $status);
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
