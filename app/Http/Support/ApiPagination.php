<?php

declare(strict_types=1);

namespace App\Http\Support;

use Illuminate\Http\Request;

/**
 * Paginación estándar, opcional y aditiva para los listados de la API (E-02).
 *
 * Vive en la capa HTTP: toma una colección ya materializada (list de arrays primitivos que devuelve el
 * QueryBus) y la trocea según los parámetros de la petición, SIN tocar el dominio ni los repositorios.
 *
 * Modos (ninguno rompe la respuesta previa, porque solo se activa si el cliente lo pide):
 *   - cursor  → paginación por cursor opaco (preferida para colecciones que crecen): `?cursor=...&per_page=...`.
 *   - offset  → paginación por página: `?page=...&per_page=...`.
 *   - ninguno → se devuelve la colección completa, sin `meta` (comportamiento histórico).
 *
 * El cursor es un token opaco (base64 de un offset) para no filtrar el modelo de almacenamiento.
 */
final class ApiPagination
{
    private const int DEFAULT_PER_PAGE = 50;

    private const int MAX_PER_PAGE = 200;

    private const string CURSOR_PREFIX = 'offset:';

    /**
     * ¿La petición pide paginar? (trae cursor, página o tamaño de página).
     */
    public static function requested(Request $request): bool
    {
        return $request->has('cursor') || $request->has('page') || $request->has('per_page');
    }

    /**
     * Tamaño de página efectivo (= LIMIT a bajar a SQL). Default 50, acotado a [1, 200].
     *
     * Traductor HTTP → SQL: el controller lo pasa al query object → handler → reader, de modo que el
     * recorte ocurra en la base y no materializando la colección completa en memoria.
     */
    public static function limit(Request $request): int
    {
        return self::perPage($request);
    }

    /**
     * Desplazamiento efectivo (= OFFSET a bajar a SQL). En modo cursor se decodifica del token opaco;
     * en modo página se calcula como `(page - 1) * per_page`.
     */
    public static function offset(Request $request): int
    {
        if ($request->has('cursor')) {
            return self::decodeCursor(self::stringParam($request, 'cursor'));
        }

        return (self::page($request) - 1) * self::perPage($request);
    }

    /**
     * Arma el envelope `data` + `meta` a partir de una página YA recortada por SQL y el total real
     * (COUNT(*) aparte). A diferencia de `paginate()`, NO trocea nada: los `$pageItems` son exactamente
     * las filas de la página pedida y `$total` el conteo completo del filtro.
     *
     * Reproduce EXACTAMENTE la forma de `paginate()` (modo cursor y modo página), para no romper el
     * contrato de respuesta ya publicado.
     *
     * @param  list<array<string, mixed>>                                          $pageItems
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public static function envelope(array $pageItems, int $total, Request $request): array
    {
        $perPage = self::perPage($request);
        $count = \count($pageItems);

        if ($request->has('cursor')) {
            $offset = self::decodeCursor(self::stringParam($request, 'cursor'));
            $next = $offset + $count;
            $hasMore = $next < $total;

            return [
                'data' => array_values($pageItems),
                'meta' => [
                    'count' => $count,
                    'per_page' => $perPage,
                    'has_more' => $hasMore,
                    'next_cursor' => $hasMore ? self::encodeCursor($next) : null,
                ],
            ];
        }

        $page = self::page($request);
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return [
            'data' => array_values($pageItems),
            'meta' => [
                'count' => $count,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'next_page' => $page < $totalPages ? $page + 1 : null,
                'prev_page' => $page > 1 ? $page - 1 : null,
            ],
        ];
    }

    /**
     * Pagina la colección respetando el modo pedido. Devuelve el cuerpo listo: `data` + `meta`.
     *
     * @param  list<array<string, mixed>>                                          $items
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public static function paginate(array $items, Request $request): array
    {
        $perPage = self::perPage($request);
        $total = \count($items);

        if ($request->has('cursor')) {
            $offset = self::decodeCursor(self::stringParam($request, 'cursor'));
            $slice = \array_slice($items, $offset, $perPage);
            $next = $offset + \count($slice);
            $hasMore = $next < $total;

            return [
                'data' => array_values($slice),
                'meta' => [
                    'count' => \count($slice),
                    'per_page' => $perPage,
                    'has_more' => $hasMore,
                    'next_cursor' => $hasMore ? self::encodeCursor($next) : null,
                ],
            ];
        }

        $page = self::page($request);
        $offset = ($page - 1) * $perPage;
        $slice = \array_slice($items, $offset, $perPage);
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return [
            'data' => array_values($slice),
            'meta' => [
                'count' => \count($slice),
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'next_page' => $page < $totalPages ? $page + 1 : null,
                'prev_page' => $page > 1 ? $page - 1 : null,
            ],
        ];
    }

    private static function perPage(Request $request): int
    {
        $raw = self::intParam($request, 'per_page', self::DEFAULT_PER_PAGE);

        return max(1, min(self::MAX_PER_PAGE, $raw));
    }

    private static function page(Request $request): int
    {
        return max(1, self::intParam($request, 'page', 1));
    }

    private static function intParam(Request $request, string $key, int $default): int
    {
        $value = $request->query($key);

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : $default;
    }

    private static function stringParam(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : '';
    }

    /**
     * Codifica un offset como cursor opaco.
     */
    private static function encodeCursor(int $offset): string
    {
        return base64_encode(self::CURSOR_PREFIX.$offset);
    }

    /**
     * Decodifica el cursor a un offset; un cursor inválido o vacío equivale al inicio (offset 0).
     */
    private static function decodeCursor(string $cursor): int
    {
        if ($cursor === '') {
            return 0;
        }

        $decoded = base64_decode($cursor, true);

        if ($decoded === false || !str_starts_with($decoded, self::CURSOR_PREFIX)) {
            return 0;
        }

        $offset = substr($decoded, \strlen(self::CURSOR_PREFIX));

        return is_numeric($offset) ? max(0, (int) $offset) : 0;
    }
}
