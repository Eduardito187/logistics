<?php

declare(strict_types=1);

namespace Logistics\Shared\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;

/**
 * Mantenimiento físico programado de las tablas calientes (append-only / alta rotación).
 *
 * Corre ANALYZE (y, salvo --analyze-only, VACUUM (ANALYZE)) sobre la lista de tablas calientes para
 * mantener estadísticas frescas del planner y recuperar espacio de tuplas muertas. Complementa al
 * autovacuum (que ya está afinado por tabla en migraciones); este comando da un barrido determinista
 * de madrugada para que las estadísticas no dependan solo de los umbrales del demonio.
 *
 * Seguro e idempotente: NO usa VACUUM FULL (reescribe la tabla y toma AccessExclusiveLock, bloquea).
 * VACUUM (ANALYZE) toma solo un lock ligero que no bloquea lecturas ni escrituras normales.
 *
 * Lo invoca el scheduler (línea sugerida reportada al orquestador) o se corre a mano.
 */
final class MaintainDatabaseCommand extends Command
{
    protected $signature = 'logistics:db:maintain
        {--analyze-only : Run only ANALYZE (skip VACUUM); lighter, for more frequent runs}
        {--tables= : Comma-separated subset of hot tables to maintain (defaults to all known hot tables)}';

    protected $description = 'Run ANALYZE (and VACUUM ANALYZE) over the hot, high-growth tables to keep them physically healthy';

    /**
     * Tablas calientes conocidas (append-only / alta rotación). Allowlist: --tables solo puede elegir
     * de acá, lo que evita interpolar identificadores arbitrarios en el SQL de VACUUM/ANALYZE.
     *
     * @var list<string>
     */
    private const HOT_TABLES = [
        'telemetry_pings',
        'telemetry_vehicle_positions',
        'execution_scan_events',
        'outbox_messages',
    ];

    public function handle(DatabaseManager $db): int
    {
        $connection = $db->connection();

        if ($connection->getDriverName() !== 'pgsql') {
            $this->warn('logistics:db:maintain solo aplica a PostgreSQL; se omite en este driver.');

            return self::SUCCESS;
        }

        $tables = $this->resolveTables();
        if ($tables === null) {
            return self::FAILURE;
        }

        $analyzeOnly = (bool) $this->option('analyze-only');
        $mode = $analyzeOnly ? 'ANALYZE' : 'VACUUM (ANALYZE)';
        $this->info(sprintf('Mantenimiento de BD (%s) sobre %d tabla(s)...', $mode, count($tables)));

        $totalMs = 0.0;
        foreach ($tables as $table) {
            // $table ya está validado contra la allowlist; la interpolación es segura.
            $sql = $analyzeOnly ? 'ANALYZE '.$table : 'VACUUM (ANALYZE) '.$table;

            $start = microtime(true);
            $connection->statement($sql);
            $elapsedMs = (microtime(true) - $start) * 1000;
            $totalMs += $elapsedMs;

            $this->line(sprintf('  %-32s %s en %.1f ms', $table, $mode, $elapsedMs));
        }

        $this->info(sprintf('Mantenimiento terminado: %d tabla(s) en %.1f ms.', count($tables), $totalMs));

        return self::SUCCESS;
    }

    /**
     * Resuelve la lista de tablas a mantener. Devuelve null si --tables trae un valor inválido.
     *
     * @return list<string>|null
     */
    private function resolveTables(): ?array
    {
        $option = $this->option('tables');
        if ($option === null || trim((string) $option) === '') {
            return self::HOT_TABLES;
        }

        $requested = array_values(array_filter(array_map(
            static fn (string $t): string => trim($t),
            explode(',', (string) $option),
        ), static fn (string $t): bool => $t !== ''));

        $unknown = array_diff($requested, self::HOT_TABLES);
        if ($unknown !== []) {
            $this->error(sprintf(
                'Tablas no reconocidas: %s. Permitidas: %s',
                implode(', ', $unknown),
                implode(', ', self::HOT_TABLES),
            ));

            return null;
        }

        return $requested;
    }
}
