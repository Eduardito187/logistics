<?php

declare(strict_types=1);

namespace Commerce\Search\Infrastructure\Index;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Indexador de la proyección de búsqueda `commerce_product_search`.
 *
 * Reconstruye la proyección leyendo la tabla del catálogo (`commerce_products`) en modo READ-ONLY por
 * query builder: NO hace `use` de clases de `commerce/catalog` ni depende de él por composer (cross-context
 * sin acople, patrón del skill), de modo que `search` sigue siendo extraíble a microservicio.
 *
 * Dos modos:
 *  - {@see reindexAll()}  → reconstrucción completa (comando `commerce:search:reindex`).
 *  - {@see indexProduct()} / {@see remove()} → indexado incremental por id (dispatcher de outbox que
 *    reacciona a `catalog.product_*`). Idempotente: upsert por `product_id`.
 *
 * El `search_vector` se calcula con `to_tsvector('spanish', name || ' ' || description || ' ' || sku)`
 * solo en PostgreSQL; en otros motores la fila se indexa sin vector y la lectura degrada a ILIKE.
 */
final class ProductSearchIndexer
{
    /** Tabla read-only del catálogo (dueño: `commerce/catalog`). */
    private const SOURCE = 'commerce_products';

    /** Proyección de búsqueda (dueño: este contexto). */
    private const TARGET = 'commerce_product_search';

    /**
     * Reconstruye la proyección completa desde el catálogo. Devuelve la cantidad de filas indexadas.
     *
     * Se indexan los productos de TODOS los estados (no solo `published`) llevando su `status`: así el
     * contrato de la API —que filtra por estado y calcula facetas por categoría en cualquier estado— se
     * mantiene intacto; el storefront filtra por `published` en la consulta. Operación set-based en una
     * transacción: vacía la proyección y la repuebla de una sola pasada (eficiente sobre millones de filas).
     *
     * @param int $chunk tamaño de lote para el camino sin FTS (motores que no soportan INSERT ... SELECT con tsvector)
     */
    public function reindexAll(int $chunk = 1000): int
    {
        if (DB::getDriverName() === 'pgsql') {
            return $this->reindexAllPostgres();
        }

        return $this->reindexAllPortable($chunk);
    }

    /**
     * Indexa (o reindexa) un único producto por su id, leyendo su fila actual del catálogo. Si el producto
     * ya no existe en el catálogo, elimina su entrada de la proyección. Idempotente (upsert por id).
     */
    public function indexProduct(string $productId): void
    {
        try {
            /** @var object|null $row */
            $row = DB::table(self::SOURCE)->find($productId);
        } catch (Throwable) {
            // El catálogo aún no está desplegado o la fuente falló: no romper el relay del outbox.
            return;
        }

        if ($row === null) {
            $this->remove($productId);

            return;
        }

        $name = $this->str($row, 'name');
        $description = $this->str($row, 'description');
        $sku = $this->str($row, 'canonical_sku');
        $categoryId = $this->nullableStr($row, 'category_id');
        $status = $this->str($row, 'status');
        $document = $this->document($name, $description, $sku);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'INSERT INTO '.self::TARGET.' (product_id, canonical_sku, name, category_id, status, indexed_at, search_vector) '
                ."VALUES (?, ?, ?, ?, ?, now(), to_tsvector('spanish', ?)) "
                .'ON CONFLICT (product_id) DO UPDATE SET '
                .'canonical_sku = EXCLUDED.canonical_sku, name = EXCLUDED.name, category_id = EXCLUDED.category_id, '
                .'status = EXCLUDED.status, indexed_at = EXCLUDED.indexed_at, search_vector = EXCLUDED.search_vector',
                [$productId, $sku, $name, $categoryId, $status, $document],
            );

            return;
        }

        DB::table(self::TARGET)->updateOrInsert(
            ['product_id' => $productId],
            [
                'canonical_sku' => $sku,
                'name' => $name,
                'category_id' => $categoryId,
                'status' => $status,
                'indexed_at' => now(),
            ],
        );
    }

    /**
     * Elimina un producto de la proyección (p. ej. si se borró del catálogo). No falla si no existe.
     */
    public function remove(string $productId): void
    {
        try {
            DB::table(self::TARGET)->where('product_id', $productId)->delete();
        } catch (Throwable) {
            // Proyección inexistente o fuente caída: nada que hacer.
        }
    }

    /**
     * Reconstrucción completa en PostgreSQL: vacía y repuebla con el `tsvector` calculado en un INSERT…SELECT.
     */
    private function reindexAllPostgres(): int
    {
        return DB::transaction(function (): int {
            DB::statement('TRUNCATE TABLE '.self::TARGET);

            DB::statement(
                'INSERT INTO '.self::TARGET.' (product_id, canonical_sku, name, category_id, status, indexed_at, search_vector) '
                .'SELECT id, canonical_sku, name, category_id, status, now(), '
                ."to_tsvector('spanish', coalesce(name, '') || ' ' || coalesce(description, '') || ' ' || coalesce(canonical_sku, '')) "
                .'FROM '.self::SOURCE,
            );

            return (int) DB::table(self::TARGET)->count();
        });
    }

    /**
     * Reconstrucción completa portable (motores sin FTS): vacía y repuebla por lotes, sin `search_vector`.
     */
    private function reindexAllPortable(int $chunk): int
    {
        return DB::transaction(function () use ($chunk): int {
            DB::table(self::TARGET)->delete();

            $indexed = 0;
            DB::table(self::SOURCE)
                ->select(['id', 'canonical_sku', 'name', 'category_id', 'status'])
                ->orderBy('id')
                ->chunk(max(1, $chunk), function ($rows) use (&$indexed): void {
                    $now = now();
                    $batch = [];
                    foreach ($rows as $row) {
                        $batch[] = [
                            'product_id' => $this->str($row, 'id'),
                            'canonical_sku' => $this->str($row, 'canonical_sku'),
                            'name' => $this->str($row, 'name'),
                            'category_id' => $this->nullableStr($row, 'category_id'),
                            'status' => $this->str($row, 'status'),
                            'indexed_at' => $now,
                        ];
                    }
                    if ($batch !== []) {
                        DB::table(self::TARGET)->insert($batch);
                        $indexed += count($batch);
                    }
                });

            return $indexed;
        });
    }

    /**
     * Texto canónico que alimenta el `tsvector`: nombre + descripción + SKU canónico.
     */
    private function document(string $name, string $description, string $sku): string
    {
        return trim($name.' '.$description.' '.$sku);
    }

    private function str(object $row, string $field): string
    {
        $value = $row->{$field} ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private function nullableStr(object $row, string $field): ?string
    {
        $value = $row->{$field} ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
