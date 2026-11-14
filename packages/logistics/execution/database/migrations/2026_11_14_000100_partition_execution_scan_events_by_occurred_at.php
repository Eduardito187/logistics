<?php

declare(strict_types=1);

use App\Exceptions\DataMigrationException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ola 3 — Particionado declarativo por RANGO de `occurred_at` de `execution_scan_events` (tabla
 * append-only de escaneos en terreno). Habilita partition pruning en las consultas por rango de tiempo
 * y el archivado/retención por DROP de particiones completas (barato, sin DELETE masivo).
 *
 * PRESERVA DATOS (regla no-destructiva): recrea la tabla como particionada con la MISMA forma
 * (`LIKE ... INCLUDING ALL` para columnas, defaults, NOT NULL y almacenamiento), copia TODAS las
 * filas, verifica que el conteo coincide y recién entonces reemplaza la original, íntegramente en una sola
 * transacción (atómico: si algo falla, se revierte y la tabla queda intacta). Idempotente: si ya
 * está particionada, no hace nada.
 *
 * La PK pasa a ser COMPUESTA `(id, occurred_at)` porque Postgres exige que la clave de partición
 * forme parte de la PK. `id` sigue siendo único en la práctica (UUID v7) y nada referencia la tabla
 * por FK (verificado), así que el cambio es transparente para Eloquent y para los demás contextos.
 *
 * NOTA SOBRE `idempotency_key`: la constraint `UNIQUE(idempotency_key)` global se reemplaza por
 * `UNIQUE(idempotency_key, occurred_at)` (la clave de partición forma parte de toda constraint
 * unique). La idempotencia sigue garantizada en la práctica: un escaneo duplicado tiene el mismo
 * `occurred_at` que el original y cae en la misma partición, donde la restricción lo atrapa.
 *
 * Solo PostgreSQL. La partición DEFAULT garantiza que ninguna inserción falle aunque caiga fuera de
 * los rangos mensuales creados.
 */
return new class extends Migration
{
    // Tuning de autovacuum append-heavy (mismos criterios que la migración de salud física existente).
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

        // Idempotencia: si execution_scan_events ya es particionada, no repetir.
        $alreadyPartitioned = DB::selectOne(
            "SELECT 1 FROM pg_partitioned_table pt JOIN pg_class c ON c.oid = pt.partrelid WHERE c.relname = 'execution_scan_events'",
        );
        if ($alreadyPartitioned !== null) {
            return;
        }

        DB::transaction(function (): void {
            // 1) Tabla particionada nueva con la MISMA forma (columnas, tipos, NOT NULL, defaults).
            DB::statement(
                'CREATE TABLE execution_scan_events_part (LIKE execution_scan_events INCLUDING DEFAULTS INCLUDING STORAGE) '
                .'PARTITION BY RANGE (occurred_at)',
            );

            // 2) PK compuesta (obligatorio incluir la clave de partición).
            DB::statement('ALTER TABLE execution_scan_events_part ADD PRIMARY KEY (id, occurred_at)');

            // 3) Particiones: una DEFAULT (atrapa cualquier rango) + mensuales en una ventana alrededor de hoy.
            $partitions = ['execution_scan_events_p_default'];
            DB::statement('CREATE TABLE execution_scan_events_p_default PARTITION OF execution_scan_events_part DEFAULT');
            foreach ($this->monthlyWindow() as [$suffix, $from, $to]) {
                $name = "execution_scan_events_p_{$suffix}";
                $partitions[] = $name;
                DB::statement(sprintf(
                    "CREATE TABLE %s PARTITION OF execution_scan_events_part FOR VALUES FROM ('%s') TO ('%s')",
                    $name,
                    $from,
                    $to,
                ));
            }

            // 4) Copia TODAS las filas y verifica el conteo (correctitud antes de reemplazar).
            DB::statement('INSERT INTO execution_scan_events_part SELECT * FROM execution_scan_events');
            $before = (int) DB::table('execution_scan_events')->count();
            $after = (int) DB::table('execution_scan_events_part')->count();
            if ($before !== $after) {
                throw new DataMigrationException("Partition copy mismatch: {$before} vs {$after} rows; aborting (data preserved).");
            }

            // 5) Reemplaza la original: elimina la vieja (libera nombres de índices) y renombra la nueva.
            DB::statement('DROP TABLE execution_scan_events');
            DB::statement('ALTER TABLE execution_scan_events_part RENAME TO execution_scan_events');
            DB::statement('ALTER TABLE execution_scan_events_part_pkey RENAME TO execution_scan_events_pkey');

            // 6) Recrea los índices con sus nombres canónicos (se propagan a las particiones).
            DB::statement('CREATE INDEX execution_scan_events_delivery_id_index ON execution_scan_events (delivery_id)');
            DB::statement('CREATE INDEX execution_scan_events_unit_id_index ON execution_scan_events (unit_id)');
            // Idempotencia: unique COMPUESTA con la clave de partición. Un escaneo duplicado tiene el
            // mismo occurred_at → misma partición → la constraint lo atrapa.
            DB::statement('CREATE UNIQUE INDEX execution_scan_events_idempotency_key_unique ON execution_scan_events (idempotency_key, occurred_at)');
            // BRIN para barridos por rango de tiempo (append-only, correlación física ideal para BRIN).
            DB::statement('CREATE INDEX execution_scan_events_occurred_at_brin ON execution_scan_events USING brin (occurred_at)');

            // 7) Re-aplica el tuning de autovacuum append-heavy en cada partición hoja.
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
            "SELECT 1 FROM pg_partitioned_table pt JOIN pg_class c ON c.oid = pt.partrelid WHERE c.relname = 'execution_scan_events'",
        );
        if ($isPartitioned === null) {
            return;
        }

        // Reversión preservando datos: aplana la tabla particionada a una tabla normal.
        DB::transaction(function (): void {
            DB::statement('CREATE TABLE execution_scan_events_flat (LIKE execution_scan_events INCLUDING DEFAULTS INCLUDING STORAGE)');
            DB::statement('ALTER TABLE execution_scan_events_flat ADD PRIMARY KEY (id)');
            DB::statement('INSERT INTO execution_scan_events_flat SELECT * FROM execution_scan_events');
            DB::statement('DROP TABLE execution_scan_events');
            DB::statement('ALTER TABLE execution_scan_events_flat RENAME TO execution_scan_events');
            DB::statement('ALTER TABLE execution_scan_events_flat_pkey RENAME TO execution_scan_events_pkey');
            DB::statement('CREATE INDEX execution_scan_events_delivery_id_index ON execution_scan_events (delivery_id)');
            DB::statement('CREATE INDEX execution_scan_events_unit_id_index ON execution_scan_events (unit_id)');
            DB::statement('CREATE UNIQUE INDEX execution_scan_events_idempotency_key_unique ON execution_scan_events (idempotency_key)');
            DB::statement('CREATE INDEX execution_scan_events_occurred_at_brin ON execution_scan_events USING brin (occurred_at)');
            DB::statement('ALTER TABLE execution_scan_events SET ('.self::AUTOVACUUM_PARAMS.')');
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
