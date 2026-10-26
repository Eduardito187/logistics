<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Salud física de outbox_messages a escala. Aditiva e idempotente: solo afina el autovacuum.
 *
 * La tabla del outbox es de altísima rotación: cada mensaje se INSERTA como 'pending', se ACTUALIZA a
 * 'dispatched' (o 'failed'/'dead_lettered') y, con la retención (otro agente), se BORRA. Ese ciclo
 * insert→update→delete genera muchas tuplas muertas. Con el default (scale_factor 0.2) el autovacuum
 * esperaría a que el 20% de millones de filas estén muertas, dejando crecer el bloat y degradando el
 * pull del relay (que ya usa el índice parcial dispatchable).
 *
 *   - vacuum_scale_factor 0.02 + threshold 1000: vaciar seguido para contener el bloat del churn.
 *   - vacuum_insert_scale_factor 0.02 (PG13+): también disparar por inserts (ráfagas de eventos nuevos).
 *   - analyze_scale_factor 0.01: estadísticas frescas; el planner debe estimar bien la selectividad de
 *     status para elegir el índice parcial en el pull.
 *   - vacuum_cost_limit 2000: que el autovacuum avance rápido bajo carga.
 *   - fillfactor 85: deja algo de espacio por página para favorecer HOT updates en la transición de
 *     estado (pending→dispatched), reduciendo el bloat en origen.
 *
 * NO fija retención por filas (DELETE): eso lo maneja otro agente; aquí solo preparamos a autovacuum
 * para recuperar el espacio que esos DELETE liberen.
 *
 * Solo PostgreSQL (motor del proyecto). En otros drivers se omite entero.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE outbox_messages SET ('
            .'fillfactor = 85, '
            .'autovacuum_vacuum_scale_factor = 0.02, '
            .'autovacuum_vacuum_threshold = 1000, '
            .'autovacuum_vacuum_insert_scale_factor = 0.02, '
            .'autovacuum_vacuum_insert_threshold = 1000, '
            .'autovacuum_analyze_scale_factor = 0.01, '
            .'autovacuum_analyze_threshold = 1000, '
            .'autovacuum_vacuum_cost_limit = 2000'
            .')',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE outbox_messages RESET ('
            .'fillfactor, autovacuum_vacuum_scale_factor, autovacuum_vacuum_threshold, '
            .'autovacuum_vacuum_insert_scale_factor, autovacuum_vacuum_insert_threshold, '
            .'autovacuum_analyze_scale_factor, autovacuum_analyze_threshold, autovacuum_vacuum_cost_limit'
            .')',
        );
    }
};
