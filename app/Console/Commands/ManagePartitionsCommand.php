<?php

declare(strict_types=1);

namespace App\Console\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

/**
 * Gestión de particiones mensuales de las tablas calientes particionadas por rango temporal (Ola 3).
 *
 * Dos acciones:
 *  - `create`: crea las particiones de los próximos N meses si no existen. Seguro e idempotente: si la
 *    partición ya existe, la salta. Debe correrse ANTES de que el mes llegue; el scheduler lo invoca
 *    mensualmente (o el deploy), pero se puede correr a mano las veces que se quiera.
 *  - `detach`: detacha las particiones más viejas que N meses (--retain-months). La partición detachada
 *    queda como tabla normal (los datos NO se borran): se pueden archivar, exportar o DROP manualmente
 *    cuando se confirme que no hacen falta. Si la partición no existe, se salta.
 *
 * Tablas soportadas: telemetry_pings, execution_scan_events, outbox_messages. Se agregan más conforme se
 * particionen. La convención de nombres es `<tabla>_p_YYYY_MM` y `<tabla>_p_default`.
 *
 * Solo PostgreSQL.
 */
final class ManagePartitionsCommand extends Command
{
    protected $signature = 'logistics:partitions:manage
        {action : "create" to create future partitions, "detach" to detach old ones}
        {--months=3 : Number of months ahead to create (create) or retain (detach)}
        {--tables= : Comma-separated subset of tables (default: all partitioned hot tables)}
        {--dry-run : Show what would happen without making changes}';

    protected $description = 'Create future monthly partitions or detach old ones for the hot partitioned tables';

    /** Límite de costo de autovacuum común a las tablas calientes (vacuum más agresivo, sin frenar). */
    private const AUTOVACUUM_COST_LIMIT = 'autovacuum_vacuum_cost_limit = 2000';

    /** Autovacuum de tablas de inserción masiva (pings, escaneos): umbrales bajos por partición. */
    private const INSERT_HEAVY_AUTOVACUUM = 'autovacuum_vacuum_scale_factor = 0.01, '
        .'autovacuum_vacuum_threshold = 2000, '
        .'autovacuum_vacuum_insert_scale_factor = 0.01, '
        .'autovacuum_vacuum_insert_threshold = 2000, '
        .'autovacuum_analyze_scale_factor = 0.005, '
        .'autovacuum_analyze_threshold = 2000, '
        .self::AUTOVACUUM_COST_LIMIT;

    /** Autovacuum de tablas de alta rotación (outbox: insert, update y delete por retención). */
    private const HIGH_CHURN_AUTOVACUUM = 'fillfactor = 85, '
        .'autovacuum_vacuum_scale_factor = 0.02, '
        .'autovacuum_vacuum_threshold = 1000, '
        .'autovacuum_vacuum_insert_scale_factor = 0.02, '
        .'autovacuum_vacuum_insert_threshold = 1000, '
        .'autovacuum_analyze_scale_factor = 0.01, '
        .'autovacuum_analyze_threshold = 1000, '
        .self::AUTOVACUUM_COST_LIMIT;

    /**
     * Tablas calientes particionadas y sus configuraciones.
     *
     * @var array<string, array{time_column: string, autovacuum: string}>
     */
    private const PARTITIONED_TABLES = [
        'telemetry_pings' => ['time_column' => 'recorded_at', 'autovacuum' => self::INSERT_HEAVY_AUTOVACUUM],
        'execution_scan_events' => ['time_column' => 'occurred_at', 'autovacuum' => self::INSERT_HEAVY_AUTOVACUUM],
        'outbox_messages' => ['time_column' => 'created_at', 'autovacuum' => self::HIGH_CHURN_AUTOVACUUM],
    ];

    public function handle(DatabaseManager $db): int
    {
        $connection = $db->connection();

        if ($connection->getDriverName() !== 'pgsql') {
            $this->warn('logistics:partitions:manage solo aplica a PostgreSQL; se omite.');

            return self::SUCCESS;
        }

        $action = $this->argument('action');
        $tables = $this->resolveTables();

        if (!is_string($action) || !in_array($action, ['create', 'detach'], true) || $tables === null) {
            if ($tables !== null) {
                $this->error('Acción inválida. Usar "create" o "detach".');
            }

            return self::FAILURE;
        }

        $months = max(1, (int) $this->option('months'));
        $dryRun = (bool) $this->option('dry-run');

        foreach ($tables as $table) {
            if (!$this->isPartitioned($connection, $table)) {
                $this->warn("  {$table}: no está particionada, se omite.");

                continue;
            }

            if ($action === 'create') {
                $this->createFuturePartitions($connection, $table, $months, self::PARTITIONED_TABLES[$table]['autovacuum'], $dryRun);
            } else {
                $this->detachOldPartitions($connection, $table, $months, $dryRun);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Crea particiones mensuales para los próximos N meses.
     */
    private function createFuturePartitions(
        ConnectionInterface $connection,
        string $table,
        int $months,
        string $autovacuum,
        bool $dryRun,
    ): void {
        $cursor = new DateTimeImmutable('first day of this month 00:00:00');

        for ($i = 0; $i < $months; $i++) {
            $next = $cursor->modify('+1 month');
            $suffix = $cursor->format('Y_m');
            $name = "{$table}_p_{$suffix}";

            // Verificar si ya existe.
            $exists = $connection->selectOne(
                "SELECT 1 FROM pg_class WHERE relname = ? AND relkind = 'r'",
                [$name],
            );

            if ($exists !== null) {
                $this->line("  {$name}: ya existe, se omite.");
                $cursor = $next;

                continue;
            }

            $from = $cursor->format('Y-m-d 00:00:00');
            $to = $next->format('Y-m-d 00:00:00');

            if ($dryRun) {
                $this->info("[DRY-RUN] Crearía {$name} FOR VALUES FROM ('{$from}') TO ('{$to}')");
            } else {
                $connection->statement(sprintf(
                    "CREATE TABLE %s PARTITION OF %s FOR VALUES FROM ('%s') TO ('%s')",
                    $name,
                    $table,
                    $from,
                    $to,
                ));
                $connection->statement("ALTER TABLE {$name} SET ({$autovacuum})");
                $this->info("  {$name}: creada [{$from}, {$to}).");
            }

            $cursor = $next;
        }
    }

    /**
     * Detacha particiones más viejas que N meses.
     */
    private function detachOldPartitions(
        ConnectionInterface $connection,
        string $table,
        int $retainMonths,
        bool $dryRun,
    ): void {
        $cutoff = (new DateTimeImmutable('first day of this month 00:00:00'))
            ->modify("-{$retainMonths} months");

        // Listar las particiones hija de la tabla (excluyendo la default).
        $partitions = $connection->select(
            "SELECT inhrelid::regclass::text AS partition_name
             FROM pg_inherits
             JOIN pg_class c ON c.oid = inhrelid
             WHERE inhparent = ?::regclass
             AND c.relname NOT LIKE '%_default'
             ORDER BY c.relname",
            [$table],
        );

        foreach ($partitions as $row) {
            $partitionName = $row->partition_name;
            // Extraer el sufijo YYYY_MM del nombre de la partición.
            if (!preg_match('/_p_(\d{4})_(\d{2})$/', $partitionName, $m)) {
                continue;
            }

            $partitionDate = new DateTimeImmutable("{$m[1]}-{$m[2]}-01 00:00:00");
            if ($partitionDate >= $cutoff) {
                continue;
            }

            if ($dryRun) {
                $this->info("[DRY-RUN] Detacharía {$partitionName} (anterior a {$cutoff->format('Y-m')})");
            } else {
                $connection->statement("ALTER TABLE {$table} DETACH PARTITION {$partitionName}");
                $this->info("  {$partitionName}: detachada (queda como tabla normal; DROP o archive manualmente).");
            }
        }
    }

    private function isPartitioned(ConnectionInterface $connection, string $table): bool
    {
        return $connection->selectOne(
            'SELECT 1 FROM pg_partitioned_table pt JOIN pg_class c ON c.oid = pt.partrelid WHERE c.relname = ?',
            [$table],
        ) !== null;
    }

    /**
     * @return list<string>|null
     */
    private function resolveTables(): ?array
    {
        $option = $this->option('tables');
        if (!is_string($option) || trim($option) === '') {
            return array_keys(self::PARTITIONED_TABLES);
        }

        $requested = array_values(array_filter(array_map(
            static fn (string $t): string => trim($t),
            explode(',', $option),
        ), static fn (string $t): bool => $t !== ''));

        $unknown = array_diff($requested, array_keys(self::PARTITIONED_TABLES));
        if ($unknown !== []) {
            $this->error(sprintf(
                'Tablas no reconocidas: %s. Permitidas: %s',
                implode(', ', $unknown),
                implode(', ', array_keys(self::PARTITIONED_TABLES)),
            ));

            return null;
        }

        return $requested;
    }
}
