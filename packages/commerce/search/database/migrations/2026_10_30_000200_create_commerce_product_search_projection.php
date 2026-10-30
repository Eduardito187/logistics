<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proyección de búsqueda del contexto `commerce/search`: índice de Full-Text Search (FTS) desacoplado del
 * esquema del catálogo. En lugar de agregar una columna `tsvector` a `commerce_products` (acoplaría el
 * schema de `commerce/catalog`), se materializa una tabla propia `commerce_product_search` que el contexto
 * posee y reconstruye desde el catálogo (comando `commerce:search:reindex` / dispatcher de outbox).
 *
 * Columnas:
 *  - `product_id`     → id del producto (PK, espejo de `commerce_products.id`); no se declara FK para no
 *                       acoplar contextos (cross-context sin dependencia, patrón del skill).
 *  - `canonical_sku`  → SKU canónico (puente con inventory); se devuelve en los resultados.
 *  - `name`           → nombre para orden estable y fallback ILIKE.
 *  - `category_id`    → faceta/filtro de navegación.
 *  - `status`         → estado de publicación (el storefront busca `published`).
 *  - `search_vector`  → documento `tsvector` (español) para la FTS; solo en PostgreSQL.
 *
 * Aditiva e idempotente: solo crea la tabla/índices si no existen; no corre aquí, solo se escribe. En
 * motores sin FTS (p. ej. sqlite) crea la tabla sin `search_vector` y la búsqueda degrada a ILIKE.
 */
return new class extends Migration
{
    private const TABLE = 'commerce_product_search';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->uuid('product_id')->primary();
                $table->string('canonical_sku')->index();
                $table->string('name')->index();
                $table->uuid('category_id')->nullable()->index();
                $table->string('status')->index();
                // Momento de la última (re)indexación de la fila; útil para operación/diagnóstico.
                $table->timestampTz('indexed_at')->nullable();
            });
        }

        // La columna `tsvector` y el índice GIN son exclusivos de PostgreSQL (motor del proyecto).
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE '.self::TABLE.' ADD COLUMN IF NOT EXISTS search_vector tsvector',
        );

        DB::statement(
            'CREATE INDEX IF NOT EXISTS commerce_product_search_vector_idx '
            .'ON '.self::TABLE.' USING gin (search_vector)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
