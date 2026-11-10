<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige el tipo de `dispatch_blackout_days.created_by`.
 *
 * La migración de creación (2026_10_14_011000) declara `created_by` como `varchar(64)` —la intención
 * actual del esquema—, pero una versión PREVIA de ese archivo lo creó como `uuid`, así que las bases ya
 * migradas con la versión vieja tienen la columna en `uuid`. El identificador de quien bloquea el día es
 * FLEXIBLE: el admin de Filament usa un id ENTERO (p. ej. "2"), que no es un UUID. Con la columna en uuid,
 * bloquear un día falla con "invalid input syntax for type uuid: 2".
 *
 * Esta migración normaliza la columna a `varchar(64)` SOLO si todavía es uuid (idempotente). El cast
 * `uuid::text` preserva los valores existentes. En bases nuevas (ya varchar) es un no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        $column = DB::selectOne(
            "select data_type from information_schema.columns
             where table_name = 'dispatch_blackout_days' and column_name = 'created_by'",
        );

        if ($column !== null && $column->data_type === 'uuid') {
            DB::statement('ALTER TABLE dispatch_blackout_days ALTER COLUMN created_by TYPE varchar(64) USING created_by::text');
        }
    }

    public function down(): void
    {
        // No se revierte: volver a uuid rompería los identificadores no-uuid (p. ej. "2"). Es correctiva.
    }
};
