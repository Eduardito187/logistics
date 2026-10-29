<?php

declare(strict_types=1);

namespace Commerce\Search\Infrastructure\Read;

use Commerce\Search\Application\Contract\ProductSearchReader;
use Commerce\Search\Application\ReadModel\CategoryFacet;
use Commerce\Search\Application\ReadModel\ProductSearchResult;
use Commerce\Search\Application\ReadModel\SearchPage;
use Commerce\Search\Domain\ValueObject\SearchCriteria;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Adaptador de lectura contra la tabla `commerce_products` (del contexto `catalog`), en modo read-only.
 *
 * Patrón cross-context del skill: NO depende de `catalog` por composer ni hace `use` de sus clases;
 * consulta su tabla con `DB::table('commerce_products')` envuelto en `try/catch`, de modo que si la tabla
 * aún no existe (catalog no desplegado) degrada a resultado vacío en vez de romper. Así cada contexto
 * sigue siendo extraíble a microservicio.
 *
 * Búsqueda de texto con **Postgres Full-Text Search** (`to_tsvector('spanish', …) @@ plainto_tsquery`);
 * orden estable por `(name, id)` y **paginación por cursor** (keyset sobre `(name, id)`, no offset) para
 * barrer millones de filas sin degradar.
 */
final class DatabaseProductSearchReader implements ProductSearchReader
{
    /** Tabla read-only del catálogo (dueño: `commerce/catalog`). */
    private const TABLE = 'commerce_products';

    public function search(SearchCriteria $criteria): SearchPage
    {
        try {
            $base = $this->baseQuery($criteria);

            // El total solo se calcula en la primera página (sin cursor): en páginas siguientes es caro y
            // no aporta. Se clona antes de aplicar keyset/orden/límite.
            $total = $criteria->cursor === null ? (int) (clone $base)->count() : null;

            $query = $base
                ->orderBy('name')
                ->orderBy('id')
                ->limit($criteria->limit + 1); // +1 para detectar si hay página siguiente.

            $this->applyCursor($query, $criteria->cursor);

            /** @var array<int, \stdClass> $rows */
            $rows = $query->get()->all();
        } catch (Throwable) {
            // La tabla del otro contexto no existe (o la fuente falló): degradar a página vacía.
            return new SearchPage([], null, null);
        }

        $hasMore = count($rows) > $criteria->limit;
        $pageRows = array_slice($rows, 0, $criteria->limit);

        $items = array_values(array_map(
            static fn (object $row): ProductSearchResult => ProductSearchResult::fromRow($row),
            $pageRows,
        ));

        $nextCursor = null;
        if ($hasMore && $pageRows !== []) {
            $last = $pageRows[array_key_last($pageRows)];
            $nextCursor = self::encodeCursor(self::str($last, 'name'), self::str($last, 'id'));
        }

        return new SearchPage($items, $nextCursor, $total);
    }

    public function facetsByCategory(string $status): array
    {
        try {
            $rows = DB::table(self::TABLE)
                ->selectRaw('category_id, count(*) as total')
                ->where('status', $status)
                ->groupBy('category_id')
                ->orderByDesc('total')
                ->get()
                ->all();
        } catch (Throwable) {
            return [];
        }

        return array_values(array_map(
            static fn (object $row): CategoryFacet => CategoryFacet::fromRow($row),
            $rows,
        ));
    }

    /**
     * Consulta base con los filtros (FTS + estado + categoría), SIN orden, keyset ni límite.
     */
    private function baseQuery(SearchCriteria $criteria): Builder
    {
        $query = DB::table(self::TABLE)->where('status', $criteria->status);

        if ($criteria->categoryId !== null) {
            $query->where('category_id', $criteria->categoryId);
        }

        if ($criteria->hasTerm()) {
            $query->whereRaw(
                "to_tsvector('spanish', coalesce(name, '') || ' ' || coalesce(description, '')) "
                .'@@ plainto_tsquery(\'spanish\', ?)',
                [$criteria->term],
            );
        }

        return $query;
    }

    /**
     * Aplica el predicado keyset del cursor sobre `(name, id)`: `name > :name OR (name = :name AND id > :id)`.
     * Un cursor nulo o inválido no agrega predicado (arranca desde el inicio).
     */
    private function applyCursor(Builder $query, ?string $cursor): void
    {
        $decoded = self::decodeCursor($cursor);
        if ($decoded === null) {
            return;
        }

        [$name, $id] = $decoded;

        $query->where(function (Builder $outer) use ($name, $id): void {
            $outer->where('name', '>', $name)
                ->orWhere(function (Builder $inner) use ($name, $id): void {
                    $inner->where('name', '=', $name)->where('id', '>', $id);
                });
        });
    }

    /**
     * Codifica el cursor keyset como un string opaco base64 de `{"n": name, "i": id}`.
     */
    private static function encodeCursor(string $name, string $id): string
    {
        return base64_encode((string) json_encode(['n' => $name, 'i' => $id]));
    }

    /**
     * Decodifica el cursor a `[name, id]`. Devuelve null si falta o es inválido (se ignora con seguridad).
     *
     * @return array{0: string, 1: string}|null
     */
    private static function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null) {
            return null;
        }

        $json = base64_decode($cursor, true);
        if ($json === false) {
            return null;
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return null;
        }

        $name = $decoded['n'] ?? null;
        $id = $decoded['i'] ?? null;
        if (! is_string($name) || ! is_string($id)) {
            return null;
        }

        return [$name, $id];
    }

    private static function str(object $row, string $field): string
    {
        $value = $row->{$field} ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
