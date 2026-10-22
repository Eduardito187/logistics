<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índices de escala para ejecución (última milla). Aditivos e idempotentes: solo aceleran lectura.
 *
 *  - execution_deliveries (created_at): el tablero del supervisor (LastMileBoard::rows) trae la cola
 *    viva ordenada por `created_at ASC` con LIMIT 500. Sin índice, Postgres ordena toda la tabla antes
 *    de recortar; con el índice recorre en orden y corta en 500. `status`/`pod_captured_at` entran en un
 *    OR dentro del mismo filtro y no se pueden cubrir con un solo índice, pero el costo dominante es el
 *    ORDER BY + LIMIT, que este índice sí resuelve.
 *  - execution_scan_events (delivery_id, type, occurred_at): la traza en tránsito de una ruta
 *    (VehicleRouteResource) filtra `delivery_id IN (...)` + `type = 'in_transit'` y ordena por
 *    `occurred_at`. El índice simple de delivery_id no acota por tipo ni da el orden; este compuesto sí.
 *
 * Solo PostgreSQL (el motor del proyecto) soporta esta construcción idempotente; en otros drivers se omite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE INDEX IF NOT EXISTS execution_deliveries_created_at_index '
            .'ON execution_deliveries (created_at)',
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS execution_scan_events_delivery_type_occurred_index '
            .'ON execution_scan_events (delivery_id, type, occurred_at)',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS execution_deliveries_created_at_index');
        DB::statement('DROP INDEX IF EXISTS execution_scan_events_delivery_type_occurred_index');
    }
};
