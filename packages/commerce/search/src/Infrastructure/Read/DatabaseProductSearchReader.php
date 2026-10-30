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
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Adaptador de lectura de la búsqueda contra la proyección propia `commerce_product_search` (dueño: este
 * contexto). La proyección la (re)construye el indexador desde el catálogo; así la lectura NO se acopla al
 * esquema de `commerce/catalog` ni depende de él por composer, y `search` sigue siendo extraíble a servicio.
 *
 * Estrategia de texto (degradado seguro, de mayor a menor capacidad):
 *  1. Proyección + PostgreSQL + término → **Full-Text Search** (`websearch_to_tsquery('spanish', :q) @@
 *     search_vector`) ordenada por relevancia (`ts_rank`) con paginación keyset por `(rank, name, id)`.
 *  2. Proyección + término sin FTS (motor no-pgsql) → **ILIKE** sobre `name`/`canonical_sku`.
 *  3. Proyección sin término → navegación por filtros, orden estable `(name, id)`.
 *  4. Proyección inexistente (aún sin indexar / search no desplegado) → **fallback ILIKE** sobre la tabla
 *     `commerce_products` del catálogo (read-only), para no dejar caído el endpoint antes del primer reindex.
 *
 * Toda la lectura va envuelta en `try/catch`: si la fuente no existe o falla, degrada a página vacía en vez
 * de romper. La paginación por cursor es keyset (no offset) para barrer millones de filas sin degradar.
 */
final class DatabaseProductSearchReader implements ProductSearchReader
{
    /** Proyección de búsqueda (dueño: este contexto). */
    private const PROJECTION = 'commerce_product_search';

    /** Tabla read-only del catálogo (fallback cuando la proyección aún no existe). */
    private const SOURCE = 'commerce_products';

    public function search(SearchCriteria $criteria): SearchPage
    {
        try {
            $projection = $this->projectionExists();

            // Camino 1: FTS por relevancia (proyección + PostgreSQL + término).
            if ($projection && $this->isPostgres() && $criteria->hasTerm()) {
                return $this->searchByRank($criteria);
            }

            // Caminos 2–4 unificados (≤3 returns): orden estable por `(name, id)` sobre la proyección si
            // existe, o el catálogo como fallback seguro si aún no se indexó. El filtro ILIKE solo se usa
            // cuando hay término pero NO hay FTS (proyección ausente o motor no-PostgreSQL).
            $table = $projection ? self::PROJECTION : self::SOURCE;
            $idColumn = $projection ? 'product_id' : 'id';
            $useLike = $criteria->hasTerm() && (!$projection || !$this->isPostgres());

            return $this->searchByName($criteria, $table, $idColumn, $useLike ? $criteria->term : null);
        } catch (Throwable) {
            // Fuente inexistente o caída: degradar a página vacía en vez de romper el endpoint.
            return new SearchPage([], null, null);
        }
    }

    public function facetsByCategory(string $status): array
    {
        $table = $this->projectionExists() ? self::PROJECTION : self::SOURCE;

        try {
            $rows = DB::table($table)
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
     * Camino FTS por relevancia (PostgreSQL): filtra con `websearch_to_tsquery @@ search_vector`, ordena por
     * `ts_rank` descendente y pagina keyset por `(rank, name, product_id)`. Se arma SQL explícito (y no el
     * query builder) para controlar el orden exacto de los bindings del `tsquery`, que aparece en el SELECT
     * del rank y en el filtro. El keyset usa el alias `rank` en un envoltorio para no recalcular el tsquery.
     */
    private function searchByRank(SearchCriteria $criteria): SearchPage
    {
        $term = (string) $criteria->term;

        // Filtro común (estado + categoría + FTS), con sus bindings en orden.
        $filter = 'status = ?';
        $filterBindings = [$criteria->status];
        if ($criteria->categoryId !== null) {
            $filter .= ' AND category_id = ?';
            $filterBindings[] = $criteria->categoryId;
        }
        $filter .= " AND websearch_to_tsquery('spanish', ?) @@ search_vector";
        $filterBindings[] = $term;

        // Total solo en la primera página (sin cursor): en páginas siguientes es caro y no aporta.
        $total = null;
        if ($criteria->cursor === null) {
            /** @var \stdClass|null $countRow */
            $countRow = DB::selectOne(
                'SELECT count(*) AS total FROM '.self::PROJECTION.' WHERE '.$filter,
                $filterBindings,
            );
            $total = $countRow === null ? 0 : (int) ($countRow->total ?? 0);
        }

        // Keyset sobre el alias `rank` (envoltorio): rank < r OR (rank = r AND name > n) OR (= AND = AND id > i).
        $keyset = '';
        $keysetBindings = [];
        $decoded = self::decodeCursor($criteria->cursor);
        if ($decoded !== null && $decoded['rank'] !== null) {
            // Cast explícito a numeric en cada comparación del rank: el binding viaja como texto decimal
            // (sin pérdida de precisión) y PostgreSQL lo compara contra la columna numeric de forma exacta.
            $keyset = 'WHERE (ranked.rank < ?::numeric OR (ranked.rank = ?::numeric AND ranked.name > ?) '
                .'OR (ranked.rank = ?::numeric AND ranked.name = ? AND ranked.id > ?)) ';
            $rank = $decoded['rank'];
            $keysetBindings = [$rank, $rank, $decoded['name'], $rank, $decoded['name'], $decoded['id']];
        }

        $sql = 'SELECT * FROM ('
            .'SELECT product_id AS id, name, canonical_sku, category_id, status, '
            // rank como numeric(.,6): valor estable y comparable EXACTO (evita la imprecisión de float en
            // el cursor keyset). Los empates de relevancia se desempatan por (name, id).
            .'ROUND(ts_rank(search_vector, '."websearch_to_tsquery('spanish', ?))::numeric, 6) AS rank "
            .'FROM '.self::PROJECTION.' WHERE '.$filter
            .') ranked '
            .$keyset
            .'ORDER BY ranked.rank DESC, ranked.name ASC, ranked.id ASC '
            .'LIMIT ?';

        // Bindings en el mismo orden en que aparecen los `?`: rank(SELECT), filtro, keyset, límite(+1).
        $bindings = array_merge([$term], $filterBindings, $keysetBindings, [$criteria->limit + 1]);

        /** @var array<int, \stdClass> $rows */
        $rows = DB::select($sql, $bindings);

        return $this->paginate(
            $rows,
            $criteria->limit,
            $total,
            static fn (object $row): string => self::encodeCursor(
                self::str($row, 'name'),
                self::str($row, 'id'),
                self::str($row, 'rank'),
            ),
        );
    }

    /**
     * Camino de orden estable por `(name, id)`: navegación por filtros y, si hay `$likeTerm`, filtro ILIKE
     * sobre `name`/`canonical_sku` (fallback sin FTS). Pagina keyset por `(name, id)` con el query builder.
     *
     * @param 'product_id'|'id' $idColumn columna de id en la tabla consultada (se proyecta como `id`)
     */
    private function searchByName(SearchCriteria $criteria, string $table, string $idColumn, ?string $likeTerm): SearchPage
    {
        $base = $this->baseNameQuery($criteria, $table, $likeTerm);

        // El total solo se calcula en la primera página (sin cursor). Se clona antes de orden/keyset/límite.
        $total = $criteria->cursor === null ? (int) (clone $base)->count() : null;

        $query = $base
            ->select([DB::raw("{$idColumn} as id"), 'name', 'canonical_sku', 'category_id', 'status'])
            ->orderBy('name')
            ->orderBy($idColumn)
            ->limit($criteria->limit + 1); // +1 para detectar si hay página siguiente.

        $this->applyNameCursor($query, $idColumn, $criteria->cursor);

        /** @var array<int, \stdClass> $rows */
        $rows = $query->get()->all();

        return $this->paginate(
            $rows,
            $criteria->limit,
            $total,
            static fn (object $row): string => self::encodeCursor(self::str($row, 'name'), self::str($row, 'id')),
        );
    }

    /**
     * Consulta base por nombre (estado + categoría + ILIKE opcional), SIN orden, keyset ni límite.
     */
    private function baseNameQuery(SearchCriteria $criteria, string $table, ?string $likeTerm): Builder
    {
        $query = DB::table($table)->where('status', $criteria->status);

        if ($criteria->categoryId !== null) {
            $query->where('category_id', $criteria->categoryId);
        }

        if ($likeTerm !== null) {
            // ILIKE en PostgreSQL (case-insensitive); LIKE en motores sin ILIKE (p. ej. sqlite de test).
            $operator = $this->isPostgres() ? 'ilike' : 'like';
            $pattern = '%'.$likeTerm.'%';
            $query->where(function (Builder $inner) use ($operator, $pattern): void {
                $inner->where('name', $operator, $pattern)
                    ->orWhere('canonical_sku', $operator, $pattern);
            });
        }

        return $query;
    }

    /**
     * Arma la página a partir de las filas crudas (una de más = hay siguiente) y el generador del cursor.
     *
     * @param array<int, object>       $rows
     * @param callable(object): string $cursorOf genera el cursor opaco de la última fila de la página
     */
    private function paginate(array $rows, int $limit, ?int $total, callable $cursorOf): SearchPage
    {
        $hasMore = count($rows) > $limit;
        $pageRows = array_slice($rows, 0, $limit);

        $items = array_values(array_map(
            static fn (object $row): ProductSearchResult => ProductSearchResult::fromRow($row),
            $pageRows,
        ));

        $nextCursor = null;
        if ($hasMore && $pageRows !== []) {
            $nextCursor = $cursorOf($pageRows[array_key_last($pageRows)]);
        }

        return new SearchPage($items, $nextCursor, $total);
    }

    /**
     * Aplica el keyset del cursor sobre `(name, id)`: `name > :name OR (name = :name AND id > :id)`.
     * Un cursor nulo o inválido no agrega predicado (arranca desde el inicio).
     */
    private function applyNameCursor(Builder $query, string $idColumn, ?string $cursor): void
    {
        $decoded = self::decodeCursor($cursor);
        if ($decoded === null) {
            return;
        }

        $name = $decoded['name'];
        $id = $decoded['id'];

        $query->where(function (Builder $outer) use ($idColumn, $name, $id): void {
            $outer->where('name', '>', $name)
                ->orWhere(function (Builder $inner) use ($idColumn, $name, $id): void {
                    $inner->where('name', '=', $name)->where($idColumn, '>', $id);
                });
        });
    }

    /**
     * Codifica el cursor keyset como string opaco base64 de `{"n": name, "i": id, "r": rank?}`. El rank solo
     * se incluye en el camino FTS por relevancia (null en la navegación por nombre).
     */
    private static function encodeCursor(string $name, string $id, ?string $rank = null): string
    {
        $payload = ['n' => $name, 'i' => $id];
        if ($rank !== null) {
            $payload['r'] = $rank;
        }

        return base64_encode((string) json_encode($payload));
    }

    /**
     * Decodifica el cursor a `['name' => ..., 'id' => ..., 'rank' => ?]`. Devuelve null si falta o es
     * inválido (se ignora con seguridad y la búsqueda arranca desde el inicio).
     *
     * @return array{name: string, id: string, rank: ?string}|null
     */
    private static function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null) {
            return null;
        }

        // Base64/JSON inválidos degradan a un decode no-arreglo; name/id se validan en un solo guard.
        $json = base64_decode($cursor, true);
        $decoded = $json === false ? [] : json_decode($json, true);
        $name = is_array($decoded) ? ($decoded['n'] ?? null) : null;
        $id = is_array($decoded) ? ($decoded['i'] ?? null) : null;
        $rank = is_array($decoded) ? ($decoded['r'] ?? null) : null;
        if (!is_string($name) || !is_string($id)) {
            return null;
        }

        return ['name' => $name, 'id' => $id, 'rank' => is_scalar($rank) ? (string) $rank : null];
    }

    private function projectionExists(): bool
    {
        try {
            return Schema::hasTable(self::PROJECTION);
        } catch (Throwable) {
            return false;
        }
    }

    private function isPostgres(): bool
    {
        return DB::getDriverName() === 'pgsql';
    }

    private static function str(object $row, string $field): string
    {
        $value = $row->{$field} ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
