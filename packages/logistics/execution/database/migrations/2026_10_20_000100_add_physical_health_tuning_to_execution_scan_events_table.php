<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Salud física de execution_scan_events a escala. Aditiva e idempotente: no toca datos ni elimina
 * índices; agrega un BRIN de apoyo y afina el autovacuum.
 *
 * 1) BRIN en execution_scan_events(occurred_at)
 *    ---------------------------------------------------------------------------------------------
 *    Cada escaneo es un hecho inmutable (append-only) y se inserta en orden temporal, así que
 *    occurred_at queda físicamente correlacionado con el orden en disco: caso ideal para BRIN.
 *
 *    TRADE-OFF: a diferencia de telemetry_pings, hoy NO existe ningún índice que sirva un barrido por
 *    occurred_at a secas: los índices existentes son el compuesto (delivery_id, type, occurred_at) —que
 *    solo sirve con delivery_id como prefijo— y el unique de idempotency_key. Por eso el BRIN no es
 *    solo complemento: es el ÚNICO soporte para las consultas por rango de tiempo (analítica diaria,
 *    retención, exportes) sobre toda la tabla, y lo da ocupando kilobytes en vez de los cientos de MB
 *    que costaría un btree sobre millones de filas. No se agrega un btree sobre occurred_at porque no
 *    hace falta acceso puntual por esa columna; si apareciera, se evaluaría en su momento.
 *
 * 2) Autovacuum agresivo (append-heavy): mismos criterios que telemetry_pings.
 *    - vacuum_scale_factor 0.01 + threshold 2000: no esperar millones de tuplas muertas.
 *    - vacuum_insert_scale_factor 0.01 (PG13+): disparar por volumen de inserts para congelar temprano
 *      y evitar el anti-wraparound masivo de golpe.
 *    - analyze_scale_factor 0.005: estadísticas frescas para estimar bien los rangos de occurred_at.
 *    - vacuum_cost_limit 2000: que el autovacuum avance rápido sobre una tabla enorme.
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
            'CREATE INDEX IF NOT EXISTS execution_scan_events_occurred_at_brin '
            .'ON execution_scan_events USING brin (occurred_at)',
        );

        DB::statement(
            'ALTER TABLE execution_scan_events SET ('
            .'autovacuum_vacuum_scale_factor = 0.01, '
            .'autovacuum_vacuum_threshold = 2000, '
            .'autovacuum_vacuum_insert_scale_factor = 0.01, '
            .'autovacuum_vacuum_insert_threshold = 2000, '
            .'autovacuum_analyze_scale_factor = 0.005, '
            .'autovacuum_analyze_threshold = 2000, '
            .'autovacuum_vacuum_cost_limit = 2000'
            .')',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS execution_scan_events_occurred_at_brin');
        DB::statement(
            'ALTER TABLE execution_scan_events RESET ('
            .'autovacuum_vacuum_scale_factor, autovacuum_vacuum_threshold, '
            .'autovacuum_vacuum_insert_scale_factor, autovacuum_vacuum_insert_threshold, '
            .'autovacuum_analyze_scale_factor, autovacuum_analyze_threshold, autovacuum_vacuum_cost_limit'
            .')',
        );
    }
};
