<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índice PARCIAL para el pull del relay del outbox (concurrencia a escala). Aditivo e idempotente.
 *
 * Consulta exacta que respalda (EloquentOutboxStore::pullBatch, con FOR UPDATE SKIP LOCKED + lease):
 *
 *     WHERE status IN ('pending','failed')  -- OutboxStatus::dispatchable()
 *       AND available_at <= :now
 *     ORDER BY available_at
 *     LIMIT :n
 *     FOR UPDATE SKIP LOCKED
 *
 * El índice compuesto que ya existe —(status, available_at)— sirve, pero indexa TODAS las filas,
 * incluidas las terminales (dispatched / dead_lettered), que a escala son la inmensa mayoría y solo
 * crecen. Este índice parcial indexa únicamente las filas despachables y ordena por available_at, de
 * modo que el relay escanea un índice compacto que no se degrada al acumularse el histórico despachado.
 * Al excluir las filas terminales, el planificador obtiene exactamente las candidatas del pull, ya
 * ordenadas, lo que acelera el SKIP LOCKED bajo múltiples workers en paralelo.
 *
 * Solo PostgreSQL (el motor del proyecto) soporta índices parciales; en otros drivers se omite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE INDEX IF NOT EXISTS outbox_messages_dispatchable_index '
            ."ON outbox_messages (available_at) WHERE status IN ('pending', 'failed')",
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS outbox_messages_dispatchable_index');
    }
};
