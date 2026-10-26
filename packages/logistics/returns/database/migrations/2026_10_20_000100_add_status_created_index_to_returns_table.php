<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índice de escala para las devoluciones (M-14 + C-4 de la auditoría de performance 2026-10-20).
 * Aditivo e idempotente: solo acelera lectura.
 *
 *  - returns (status, created_at): `EloquentReturnOrderRepository::listBy` filtra por `status` y ordena
 *    por `created_at` (`WHERE status = ? ORDER BY created_at`). Había un índice suelto de `status`, pero
 *    ninguno daba el orden por `created_at`; este compuesto resuelve filtro + orden en una pasada y evita
 *    ordenar toda la tabla a volumen.
 *
 * Se crea con `CREATE INDEX CONCURRENTLY` (convención C-4, ver docs/perf/indices-concurrently.md): un
 * índice normal toma `ACCESS EXCLUSIVE` y bloquea escrituras en `returns` mientras dura; CONCURRENTLY lo
 * construye sin bloquear al tráfico de escritura, a cambio de no poder correr dentro de una transacción.
 * Por eso la migración declara `$withinTransaction = false` (el migrador no la envuelve en transacción).
 *
 * En el entorno de test (RefreshDatabase) las migraciones corren en `migrate:fresh`, ANTES de que arranque
 * la transacción por-test; con `$withinTransaction = false` la sentencia CONCURRENTLY no choca con esa
 * transacción y se ejecuta igual que en producción (no hace falta degradar a índice normal).
 *
 * Solo PostgreSQL (el motor del proyecto) soporta CONCURRENTLY; en otros drivers se omite.
 */
return new class extends Migration
{
    /**
     * CONCURRENTLY no puede ejecutarse dentro de una transacción; el migrador no debe envolver esta
     * migración (ver C-4 / docs/perf/indices-concurrently.md).
     */
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS returns_status_created_at_index '
            .'ON returns (status, created_at)',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // DROP INDEX CONCURRENTLY tampoco corre en transacción (de ahí $withinTransaction = false).
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS returns_status_created_at_index');
    }
};
