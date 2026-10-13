<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Http\Concerns;

use Illuminate\Http\JsonResponse;
use Logistics\Promise\Domain\Exception\PromiseException;

/**
 * Envelope JSON uniforme para los endpoints del contexto de promesa.
 *
 * Recursos y colecciones viajan bajo `data`; los errores de regla de negocio bajo `error`. Mantener
 * esta forma estable es parte del contrato con los canales (web, call center, app de clientes).
 */
trait RespondsWithJson
{
    /**
     * Respuesta de un recurso simple.
     *
     * @param array<string, mixed> $data
     */
    protected function resource(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status);
    }

    /**
     * Respuesta de una colección, con `meta.count` para que el canal no tenga que contar.
     *
     * @param list<array<string, mixed>> $items
     */
    protected function collection(array $items): JsonResponse
    {
        return new JsonResponse([
            'data' => $items,
            'meta' => ['count' => \count($items)],
        ]);
    }

    /**
     * Error de entrada (422) con la misma forma que una ValidationException, para parámetros de ruta
     * que no pasan por un FormRequest (p. ej. un id de promesa con formato inválido).
     *
     * @param array<string, list<string>> $errors
     */
    protected function invalidInput(array $errors): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'type' => 'invalid_input',
                'message' => 'The given data was invalid.',
            ],
            'errors' => $errors,
        ], 422);
    }

    /**
     * Mapea un error de dominio a HTTP: "no existe …" es un 404; el resto son reglas de negocio
     * incumplidas (cupo agotado, transición no permitida, reserva vencida) y responden 422.
     */
    protected function domainError(PromiseException $exception): JsonResponse
    {
        $notFound = str_contains($exception->getMessage(), 'no existe');

        return new JsonResponse([
            'error' => [
                'type' => $notFound ? 'not_found' : 'business_rule_violation',
                'message' => $exception->getMessage(),
            ],
        ], $notFound ? 404 : 422);
    }
}
