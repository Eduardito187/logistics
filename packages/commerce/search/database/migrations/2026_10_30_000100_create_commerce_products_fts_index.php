<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índice GIN funcional para acelerar la Full-Text Search en español sobre `commerce_products`
 * (nombre + descripción). Aditivo e idempotente: solo acelera lectura, no cambia datos ni esquema lógico.
 *
 * La tabla es dueña del contexto `commerce/catalog`; por eso esta migración lleva un prefijo de fecha
 * POSTERIOR a las migraciones de catalog (`2026_10_29_0002*`): debe correr después de que la tabla exista.
 * Si la tabla todavía no existe (catalog no desplegado), no hace nada y no rompe.
 *
 * Solo PostgreSQL (el motor del proyecto) soporta `to_tsvector`/GIN; en otros drivers se omite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE INDEX IF NOT EXISTS commerce_products_fts_idx '
            ."ON commerce_products USING gin (to_tsvector('spanish', coalesce(name, '') || ' ' || coalesce(description, '')))",
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS commerce_products_fts_idx');
    }
};
