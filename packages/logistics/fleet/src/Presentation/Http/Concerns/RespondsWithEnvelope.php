<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Http\Concerns;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Envelope de respuesta común a todos los controllers de Fleet.
 *
 * Los recursos van siempre bajo `data`; los listados agregan `meta.count`. Así el contrato con la
 * app Flutter y los canales es predecible y uniforme entre endpoints.
 */
trait RespondsWithEnvelope
{
    /**
     * Respuesta de un recurso o payload simple.
     *
     * @param array<string, mixed> $data
     */
    private function respondData(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status);
    }

    /**
     * Respuesta de un listado con conteo en `meta`.
     *
     * @param list<array<string, mixed>> $items
     */
    private function respondCollection(array $items): JsonResponse
    {
        return new JsonResponse([
            'data' => $items,
            'meta' => ['count' => count($items)],
        ], 200);
    }

    /**
     * Listado con BÚSQUEDA (`q`) y paginación estándar OPT-IN (`page`/`per_page` o `cursor`).
     *
     * `q` busca el texto, sin distinguir mayúsculas, dentro de los campos indicados de cada ítem. Sin
     * parámetros de paginación devuelve la colección completa con `meta.count` (comportamiento histórico).
     * Se filtra en memoria a propósito: son catálogos chicos de flota (vehículos, choferes), no tablas de
     * millones de filas; el filtro de estado sí baja a la consulta.
     *
     * @param list<array<string, mixed>> $items
     * @param list<string>               $searchKeys campos de texto donde busca `q`
     */
    private function respondSearchable(Request $request, array $items, array $searchKeys): JsonResponse
    {
        $q = $request->query('q');
        $needle = is_string($q) ? mb_strtolower(trim($q)) : '';

        if ($needle !== '') {
            $items = array_values(array_filter($items, static function (array $item) use ($needle, $searchKeys): bool {
                foreach ($searchKeys as $key) {
                    $value = $item[$key] ?? null;
                    if (is_scalar($value) && str_contains(mb_strtolower((string) $value), $needle)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        if (!ApiPagination::requested($request)) {
            return $this->respondCollection($items);
        }

        return new JsonResponse(ApiPagination::paginate($items, $request), 200);
    }

    /**
     * Acuse de un comando aceptado y aplicado (los comandos del dominio devuelven void).
     *
     * @param array<string, mixed> $data
     */
    private function respondAccepted(array $data = [], int $status = 201): JsonResponse
    {
        return new JsonResponse(['data' => ['status' => 'ok'] + $data], $status);
    }
}
