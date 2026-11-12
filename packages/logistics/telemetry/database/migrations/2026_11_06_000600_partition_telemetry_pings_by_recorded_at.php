<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * C6 — Particionado declarativo por RANGO de `recorded_at` de `telemetry_pings` (tabla append-only de
 * altísimo volumen a escala GPS). Habilita el poda de particiones (partition pruning) en las consultas por
 * rango de tiempo y el archivado/retención por DROP de particiones completas (barato, sin DELETE masivo).
 *
 * PRESERVA DATOS (regla no-destructiva): no es un `migrate:fresh` ni un wipe. Recrea la tabla como
 * particionada con la MISMA forma (`LIKE ... INCLUDING DEFAULTS`), copia TODAS las filas, verifica que el
 * conteo coincide y recién entonces reemplaza la original, el proceso entero en una sola transacción (atómico: si algo
 * falla, se revierte y la tabla queda intacta). Idempotente: si ya está particionada, no hace nada.
 *
 * La PK pasa a ser COMPUESTA `(id, recorded_at)` porque Postgres exige que la clave de partición forme
 * parte de la PK. `id` sigue siendo único en la práctica (UUID v7) y nada referencia la tabla por FK
 * (verificado), así que el cambio es transparente para Eloquent y para los demás contextos.
 *
 * Nota: `execution_scan_events` NO se particiona por el mismo criterio — su `UNIQUE(idempotency_key)`
 * (idempotencia offline) tendría que incluir la clave de partición, lo que ROMPERÍA la unicidad global de
 * la clave de idempotencia. El BRIN sobre `occurred_at` ya da el barrido por rango sin ese riesgo.
 *
 * Solo PostgreSQL. La partición DEFAULT garantiza que ninguna inserción falle aunque caiga fuera de los
 * rangos mensuales creados.
 */
return new class extends Migration
{
    // Tuning de autovacuum append-heavy (mismos criterios que la migración de salud física de la tabla).
    private const AUTOVACUUM_PARAMS = 'autovacuum_vacuum_scale_factor = 0.01, '
        .'autovacuum_vacuum_threshold = 2000, '
        .'autovacuum_vacuum_insert_scale_factor = 0.01, '
        .'autovacuum_vacuum_insert_threshold = 2000, '
        .'autovacuum_analyze_scale_factor = 0.005, '
        .'autovacuum_analyze_threshold = 2000, '
        .'autovacuum_vacuum_cost_limit = 2000';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Idempotencia: si telemetry_pings ya es una tabla particionada, no repetir.
        $alreadyPartitioned = DB::selectOne(
            "select 1 from pg_partitioned_table pt join pg_class c on c.oid = pt.partrelid where c.relname = 'telemetry_pings'",
        );
        if ($alreadyPartitioned !== null) {
            return;
        }

        DB::transaction(function (): void {
            // 1) Tabla particionada nueva con la MISMA forma (columnas, tipos, NOT NULL, defaults).
            DB::statement(
                'CREATE TABLE telemetry_pings_part (LIKE telemetry_pings INCLUDING DEFAULTS INCLUDING STORAGE) '
                .'PARTITION BY RANGE (recorded_at)',
            );

            // 2) PK compuesta (obligatorio incluir la clave de partición).
            DB::statement('ALTER TABLE telemetry_pings_part ADD PRIMARY KEY (id, recorded_at)');

            // 3) Particiones: una DEFAULT (atrapa cualquier rango) + mensuales en una ventana alrededor de hoy.
            $partitions = ['telemetry_pings_p_default'];
            DB::statement('CREATE TABLE telemetry_pings_p_default PARTITION OF telemetry_pings_part DEFAULT');
            foreach ($this->monthlyWindow() as [$suffix, $from, $to]) {
                $name = "telemetry_pings_p_{$suffix}";
                $partitions[] = $name;
                DB::statement(sprintf(
                    "CREATE TABLE %s PARTITION OF telemetry_pings_part FOR VALUES FROM ('%s') TO ('%s')",
                    $name,
                    $from,
                    $to,
                ));
            }

            // 4) Copia TODAS las filas y verifica el conteo (correctitud antes de reemplazar).
            DB::statement('INSERT INTO telemetry_pings_part SELECT * FROM telemetry_pings');
            $before = (int) DB::table('telemetry_pings')->count();
            $after = (int) DB::table('telemetry_pings_part')->count();
            if ($before !== $after) {
                // Fuerza el rollback de toda la transacción: la tabla original queda intacta.
                throw new RuntimeException("Partition copy mismatch: {$before} vs {$after} rows; aborting (data preserved).");
            }

            // 5) Reemplaza la original: se elimina la vieja (libera nombres de índices) y se renombra la nueva.
            DB::statement('DROP TABLE telemetry_pings');
            DB::statement('ALTER TABLE telemetry_pings_part RENAME TO telemetry_pings');
            DB::statement('ALTER TABLE telemetry_pings_part_pkey RENAME TO telemetry_pings_pkey');

            // 6) Recrea los índices con sus nombres canónicos (se propagan a las particiones).
            DB::statement('CREATE INDEX telemetry_pings_vehicle_id_index ON telemetry_pings (vehicle_id)');
            DB::statement('CREATE INDEX telemetry_pings_route_id_index ON telemetry_pings (route_id)');
            DB::statement('CREATE INDEX telemetry_pings_recorded_at_index ON telemetry_pings (recorded_at)');
            DB::statement('CREATE INDEX telemetry_pings_trip_id_index ON telemetry_pings (trip_id)');
            DB::statement('CREATE INDEX telemetry_pings_vehicle_id_recorded_at_index ON telemetry_pings (vehicle_id, recorded_at)');
            DB::statement('CREATE INDEX telemetry_pings_recorded_at_brin ON telemetry_pings USING brin (recorded_at)');

            // 7) Re-aplica el tuning de autovacuum append-heavy. En una tabla PARTICIONADA el padre no tiene
            // almacenamiento propio: los parámetros van en cada partición HOJA (Postgres lo exige). Las
            // particiones futuras que cree el ops deberán heredar este mismo tuning.
            foreach ($partitions as $partition) {
                DB::statement('ALTER TABLE '.$partition.' SET ('.self::AUTOVACUUM_PARAMS.')');
            }
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $isPartitioned = DB::selectOne(
            "select 1 from pg_partitioned_table pt join pg_class c on c.oid = pt.partrelid where c.relname = 'telemetry_pings'",
        );
        if ($isPartitioned === null) {
            return;
        }

        // Reversión preservando datos: aplana la tabla particionada a una tabla normal con la misma forma.
        DB::transaction(function (): void {
            DB::statement('CREATE TABLE telemetry_pings_flat (LIKE telemetry_pings INCLUDING DEFAULTS INCLUDING STORAGE)');
            DB::statement('ALTER TABLE telemetry_pings_flat ADD PRIMARY KEY (id)');
            DB::statement('INSERT INTO telemetry_pings_flat SELECT * FROM telemetry_pings');
            DB::statement('DROP TABLE telemetry_pings');
            DB::statement('ALTER TABLE telemetry_pings_flat RENAME TO telemetry_pings');
            DB::statement('ALTER TABLE telemetry_pings_flat_pkey RENAME TO telemetry_pings_pkey');
            DB::statement('CREATE INDEX telemetry_pings_vehicle_id_index ON telemetry_pings (vehicle_id)');
            DB::statement('CREATE INDEX telemetry_pings_route_id_index ON telemetry_pings (route_id)');
            DB::statement('CREATE INDEX telemetry_pings_recorded_at_index ON telemetry_pings (recorded_at)');
            DB::statement('CREATE INDEX telemetry_pings_trip_id_index ON telemetry_pings (trip_id)');
            DB::statement('CREATE INDEX telemetry_pings_vehicle_id_recorded_at_index ON telemetry_pings (vehicle_id, recorded_at)');
            DB::statement('CREATE INDEX telemetry_pings_recorded_at_brin ON telemetry_pings USING brin (recorded_at)');
            // Restaura el tuning de autovacuum en la tabla ya aplanada (estado post-salud-física).
            DB::statement('ALTER TABLE telemetry_pings SET ('.self::AUTOVACUUM_PARAMS.')');
        });
    }

    /**
     * Ventana de particiones mensuales alrededor de hoy (2 meses atrás … 3 adelante). Cada tupla es
     * [sufijo AAAA_MM, inicio inclusivo, fin exclusivo]. Lo que caiga fuera va a la partición DEFAULT.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function monthlyWindow(): array
    {
        $out = [];
        $start = new DateTimeImmutable('first day of this month 00:00:00');
        $cursor = $start->modify('-2 months');
        for ($i = 0; $i < 6; $i++) {
            $next = $cursor->modify('+1 month');
            $out[] = [$cursor->format('Y_m'), $cursor->format('Y-m-d 00:00:00'), $next->format('Y-m-d 00:00:00')];
            $cursor = $next;
        }

        return $out;
    }
};
