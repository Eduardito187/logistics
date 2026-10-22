<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índices de escala para telemetría. Aditivos e idempotentes: solo aceleran lectura.
 *
 *  - telemetry_trips (vehicle_id, status, started_at): `activeForVehicle` busca el viaje activo de un
 *    vehículo (`vehicle_id = ? AND status = 'active'`) ordenado por `started_at DESC`. Había índices
 *    sueltos de vehicle_id y status, pero ninguno daba el orden; este compuesto resuelve filtro + orden.
 *  - telemetry_trips (started_at): `recent` y `listBy` ordenan por `started_at DESC` sin filtrar por
 *    vehículo. `started_at` no estaba indexado pese a ser el criterio de orden por defecto en todas las
 *    listas (repositorio y panel Filament). Este índice evita ordenar toda la tabla.
 *  - telemetry_alerts (vehicle_id, occurred_at): `listBy` por vehículo ordena siempre por
 *    `occurred_at DESC`; el índice simple de occurred_at ordena global pero no acota por vehículo.
 *  - telemetry_alerts PARCIAL (occurred_at) WHERE acknowledged_at IS NULL: la cola de alertas SIN
 *    reconocer ordenada por occurred_at es el acceso caliente del operador. El índice parcial indexa
 *    solo las pendientes (las reconocidas, mayoría a escala, quedan fuera) y da el orden en una pasada.
 *
 * Nota: telemetry_pings ya está servido por el compuesto (vehicle_id, recorded_at). El barrido diario
 * por rango de `recorded_at` (append-only) sería un candidato a BRIN, pero ya existe un btree sobre
 * recorded_at; cambiarlo a BRIN es una decisión de rediseño (no aditiva) y se deja fuera de alcance.
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
            'CREATE INDEX IF NOT EXISTS telemetry_trips_vehicle_status_started_index '
            .'ON telemetry_trips (vehicle_id, status, started_at)',
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS telemetry_trips_started_at_index '
            .'ON telemetry_trips (started_at)',
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS telemetry_alerts_vehicle_occurred_index '
            .'ON telemetry_alerts (vehicle_id, occurred_at)',
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS telemetry_alerts_unacknowledged_index '
            .'ON telemetry_alerts (occurred_at) WHERE acknowledged_at IS NULL',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS telemetry_trips_vehicle_status_started_index');
        DB::statement('DROP INDEX IF EXISTS telemetry_trips_started_at_index');
        DB::statement('DROP INDEX IF EXISTS telemetry_alerts_vehicle_occurred_index');
        DB::statement('DROP INDEX IF EXISTS telemetry_alerts_unacknowledged_index');
    }
};
