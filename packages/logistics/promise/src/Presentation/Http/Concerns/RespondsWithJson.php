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
     * Mapea un error de dominio a HTTP. Prioriza el `kind` explícito de la excepción (reserva del
     * cliente): franja ya tomada → 409, día no despachable → 422, recurso inexistente → 404. Sin kind,
     * cae a la heurística histórica: "no existe …" es un 404 y el resto reglas de negocio (422).
     */
    protected function domainError(PromiseException $exception): JsonResponse
    {
        [$type, $status] = $this->classify($exception);

        return new JsonResponse([
            'error' => [
                'type' => $type,
                'message' => $exception->getMessage(),
            ],
        ], $status);
    }

    /**
     * Devuelve el par [tipo de error, status HTTP] para la excepción, según su `kind` o, en su ausencia,
     * la heurística del mensaje.
     *
     * @return array{0: string, 1: int}
     */
    private function classify(PromiseException $exception): array
    {
        switch ($exception->kind()) {
            case PromiseException::KIND_SLOT_TAKEN:
                return ['slot_taken', 409];
            case PromiseException::KIND_DAY_UNAVAILABLE:
                return ['day_unavailable', 422];
            case PromiseException::KIND_NOT_FOUND:
                return ['not_found', 404];
        }

        if (str_contains($exception->getMessage(), 'no existe')) {
            return ['not_found', 404];
        }

        return ['business_rule_violation', 422];
    }
}
