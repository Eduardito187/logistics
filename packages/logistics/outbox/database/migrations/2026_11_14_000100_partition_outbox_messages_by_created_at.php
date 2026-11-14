<?php

declare(strict_types=1);

use App\Exceptions\DataMigrationException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ola 3 — Particionado declarativo por RANGO de `created_at` de `outbox_messages` (tabla de altísima
 * rotación: insert → update → delete por retención). Habilita partition pruning en los barridos del relay
 * y la poda por retención (DROP partición vieja = instantáneo vs DELETE masivo).
 *
 * PRESERVA DATOS (regla no-destructiva): recrea la tabla como particionada con la MISMA forma, copia
 * TODAS las filas, verifica el conteo y reemplaza la original, íntegramente en una sola transacción.
 * Idempotente: si ya está particionada, no hace nada.
 *
 * La PK pasa a ser COMPUESTA `(id, created_at)` porque Postgres exige la clave de partición en la PK.
 * `id` sigue siendo UUID v7 único en la práctica. La constraint `UNIQUE(event_id)` pasa a ser
 * `UNIQUE(event_id, created_at)` (el evento duplicado tiene el mismo `created_at` → misma partición
 * → la constraint lo atrapa). El índice parcial dispatchable (status, available_at) ya NO existe como
 * tal (la migración posterior lo convirtió a índice compuesto); se recrea aquí como heredado.
 *
 * Solo PostgreSQL. La partición DEFAULT garantiza que ninguna inserción falle fuera del rango.
 */
return new class extends Migration
{
    // Tuning de autovacuum de alta rotación (mismos criterios que la migración de salud física existente).
    private const AUTOVACUUM_PARAMS = 'fillfactor = 85, '
        .'autovacuum_vacuum_scale_factor = 0.02, '
        .'autovacuum_vacuum_threshold = 1000, '
        .'autovacuum_vacuum_insert_scale_factor = 0.02, '
        .'autovacuum_vacuum_insert_threshold = 1000, '
        .'autovacuum_analyze_scale_factor = 0.01, '
        .'autovacuum_analyze_threshold = 1000, '
        .'autovacuum_vacuum_cost_limit = 2000';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Idempotencia: si outbox_messages ya es particionada, no repetir.
        $alreadyPartitioned = DB::selectOne(
            "SELECT 1 FROM pg_partitioned_table pt JOIN pg_class c ON c.oid = pt.partrelid WHERE c.relname = 'outbox_messages'",
        );
        if ($alreadyPartitioned !== null) {
            return;
        }

        DB::transaction(function (): void {
            // 1) Tabla particionada nueva con la MISMA forma.
            DB::statement(
                'CREATE TABLE outbox_messages_part (LIKE outbox_messages INCLUDING DEFAULTS INCLUDING STORAGE) '
                .'PARTITION BY RANGE (created_at)',
            );

            // 2) PK compuesta (obligatorio incluir la clave de partición).
            DB::statement('ALTER TABLE outbox_messages_part ADD PRIMARY KEY (id, created_at)');

            // 3) Particiones: una DEFAULT + mensuales en la ventana.
            $partitions = ['outbox_messages_p_default'];
            DB::statement('CREATE TABLE outbox_messages_p_default PARTITION OF outbox_messages_part DEFAULT');
            foreach ($this->monthlyWindow() as [$suffix, $from, $to]) {
                $name = "outbox_messages_p_{$suffix}";
                $partitions[] = $name;
                DB::statement(sprintf(
                    "CREATE TABLE %s PARTITION OF outbox_messages_part FOR VALUES FROM ('%s') TO ('%s')",
                    $name,
                    $from,
                    $to,
                ));
            }

            // 4) Copia TODAS las filas y verifica el conteo.
            DB::statement('INSERT INTO outbox_messages_part SELECT * FROM outbox_messages');
            $before = (int) DB::table('outbox_messages')->count();
            $after = (int) DB::table('outbox_messages_part')->count();
            if ($before !== $after) {
                throw new DataMigrationException("Partition copy mismatch: {$before} vs {$after} rows; aborting (data preserved).");
            }

            // 5) Reemplaza la original.
            DB::statement('DROP TABLE outbox_messages');
            DB::statement('ALTER TABLE outbox_messages_part RENAME TO outbox_messages');
            DB::statement('ALTER TABLE outbox_messages_part_pkey RENAME TO outbox_messages_pkey');

            // 6) Recrea los índices con sus nombres canónicos (se propagan a las particiones).
            DB::statement('CREATE UNIQUE INDEX outbox_messages_event_id_unique ON outbox_messages (event_id, created_at)');
            DB::statement('CREATE INDEX outbox_messages_aggregate_id_index ON outbox_messages (aggregate_id)');
            DB::statement('CREATE INDEX outbox_messages_event_name_index ON outbox_messages (event_name)');
            DB::statement('CREATE INDEX outbox_messages_status_index ON outbox_messages (status)');
            DB::statement('CREATE INDEX outbox_messages_available_at_index ON outbox_messages (available_at)');
            DB::statement('CREATE INDEX outbox_messages_status_available_at_index ON outbox_messages (status, available_at)');

            // 7) Re-aplica el tuning de autovacuum + fillfactor en cada partición hoja.
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
            "SELECT 1 FROM pg_partitioned_table pt JOIN pg_class c ON c.oid = pt.partrelid WHERE c.relname = 'outbox_messages'",
        );
        if ($isPartitioned === null) {
            return;
        }

        // Reversión preservando datos: aplana la tabla particionada.
        DB::transaction(function (): void {
            DB::statement('CREATE TABLE outbox_messages_flat (LIKE outbox_messages INCLUDING DEFAULTS INCLUDING STORAGE)');
            DB::statement('ALTER TABLE outbox_messages_flat ADD PRIMARY KEY (id)');
            DB::statement('INSERT INTO outbox_messages_flat SELECT * FROM outbox_messages');
            DB::statement('DROP TABLE outbox_messages');
            DB::statement('ALTER TABLE outbox_messages_flat RENAME TO outbox_messages');
            DB::statement('ALTER TABLE outbox_messages_flat_pkey RENAME TO outbox_messages_pkey');
            DB::statement('CREATE UNIQUE INDEX outbox_messages_event_id_unique ON outbox_messages (event_id)');
            DB::statement('CREATE INDEX outbox_messages_aggregate_id_index ON outbox_messages (aggregate_id)');
            DB::statement('CREATE INDEX outbox_messages_event_name_index ON outbox_messages (event_name)');
            DB::statement('CREATE INDEX outbox_messages_status_index ON outbox_messages (status)');
            DB::statement('CREATE INDEX outbox_messages_available_at_index ON outbox_messages (available_at)');
            DB::statement('CREATE INDEX outbox_messages_status_available_at_index ON outbox_messages (status, available_at)');
            DB::statement('ALTER TABLE outbox_messages SET ('.self::AUTOVACUUM_PARAMS.')');
        });
    }

    /**
     * Ventana de particiones mensuales alrededor de hoy (2 meses atrás … 3 adelante).
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
