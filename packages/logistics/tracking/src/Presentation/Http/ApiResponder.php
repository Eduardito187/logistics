<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Http;

use Illuminate\Http\JsonResponse;

/**
 * Envelope de respuesta común para los controllers HTTP del contexto de seguimiento.
 *
 * Mantiene una forma coherente en las tres puertas: los recursos viajan bajo "data" y los errores
 * bajo "error" (con "code" y "message"). Presentation arma el JSON; nunca conoce Infrastructure.
 *
 * Es un trait sin estado, pensado para controllers invocables o de recurso. Cuando exista el manejo
 * uniforme RFC 7807 (P1-PLAT-09) este envelope se alineará a ese catálogo de errores.
 */
trait ApiResponder
{
    /**
     * Respuesta exitosa con el recurso bajo la clave "data".
     *
     * @param array<string, mixed> $data
     */
    private function data(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status);
    }

    /**
     * Respuesta de error con un código estable (legible por máquina) y un mensaje humano.
     */
    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
