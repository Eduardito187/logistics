<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ola 3 — Vista materializada `cockpit_daily_summary` para los dashboards pesados (Control Tower).
 *
 * Hoy los cockpits (CockpitPulse, CockpitTrends, OperationsBrief) ejecutan COUNT/SUM/GROUP BY sobre
 * millones de filas cada ~20s de polling. A 2k clientes, incluso con caché anti-stampede, un solo
 * refresco de la agregación es costoso porque cruza shipments, execution_deliveries, execution_scan_events
 * y telemetry_pings.
 *
 * La vista materializada calcula las agregaciones una vez (por un REFRESH CONCURRENTLY programado) y
 * las consultas de los cockpits leen de la vista en vez de hacer los JOINs/COUNTs cada vez. El
 * REFRESH CONCURRENTLY no toma lock exclusivo: los lectores siguen leyendo la versión anterior
 * mientras se actualiza.
 *
 * Refresco: el scheduler invoca `logistics:cockpit:refresh` cada 30 segundos (más barato que N clientes
 * calculando en paralelo, y el dato operativo tolera 30s de latencia). La vista tiene un UNIQUE INDEX
 * (requisito de REFRESH CONCURRENTLY).
 *
 * Solo PostgreSQL. Aditiva e idempotente (CREATE ... IF NOT EXISTS no aplica a matviews, pero se chequea).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Idempotencia: si la vista ya existe, no recrear.
        $exists = DB::selectOne(
            "SELECT 1 FROM pg_matviews WHERE matviewname = 'cockpit_daily_summary'",
        );
        if ($exists !== null) {
            return;
        }

        // Vista materializada con las métricas que hoy calculan los cockpits por request. Un solo
        // registro con las agregaciones del "día operativo en curso" (fecha de la plataforma). Se
        // refresca por comando; las lecturas son triviales (una fila).
        DB::statement("
            CREATE MATERIALIZED VIEW cockpit_daily_summary AS
            SELECT
                CURRENT_DATE AS summary_date,
                -- Envíos activos (no terminales)
                (SELECT COUNT(*) FROM shipments WHERE status NOT IN ('DELIVERED','FAILED','CANCELLED'))::int AS active_shipments,
                -- Entregas en tránsito
                (SELECT COUNT(*) FROM execution_deliveries WHERE status = 'IN_TRANSIT')::int AS in_transit,
                -- Entregados hoy
                (SELECT COUNT(*) FROM execution_deliveries WHERE status = 'DELIVERED' AND updated_at >= CURRENT_DATE)::int AS delivered_today,
                -- Fallidas hoy
                (SELECT COUNT(*) FROM execution_deliveries WHERE status = 'FAILED' AND updated_at >= CURRENT_DATE)::int AS failed_today,
                -- Total entregas activas (no terminales)
                (SELECT COUNT(*) FROM execution_deliveries WHERE status NOT IN ('DELIVERED','FAILED','CANCELLED'))::int AS active_deliveries,
                -- Alertas de telemetría sin reconocer
                (SELECT COUNT(*) FROM telemetry_alerts WHERE acknowledged_at IS NULL)::int AS unacknowledged_alerts,
                -- Outbox pendiente (backlog del relay)
                (SELECT COUNT(*) FROM outbox_messages WHERE status IN ('pending','failed'))::int AS outbox_backlog,
                -- Timestamp del último refresco
                NOW() AS refreshed_at
            WITH NO DATA
        ");

        // UNIQUE INDEX obligatorio para REFRESH CONCURRENTLY (Postgres lo exige).
        DB::statement('CREATE UNIQUE INDEX cockpit_daily_summary_date_unique ON cockpit_daily_summary (summary_date)');

        // Primer poblado (REFRESH sin CONCURRENTLY porque la vista está vacía con NO DATA).
        DB::statement('REFRESH MATERIALIZED VIEW cockpit_daily_summary');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP MATERIALIZED VIEW IF EXISTS cockpit_daily_summary');
    }
};
