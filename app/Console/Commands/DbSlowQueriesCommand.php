<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Top-N de consultas por tiempo (total o medio) a partir de `pg_stat_statements` (auditoría M-6).
 *
 * La extensión la habilita el frente de infra (`shared_preload_libraries = 'pg_stat_statements'` +
 * `CREATE EXTENSION`). Este comando solo la CONSUME para diagnóstico. Si la extensión no está disponible
 * (p. ej. el entorno de test), degrada con gracia: avisa y termina con código 0, sin reventar.
 *
 * Uso:
 *   php artisan logistics:db:slow-queries --limit=20 --order=total
 *   php artisan logistics:db:slow-queries --order=mean
 */
final class DbSlowQueriesCommand extends Command
{
    protected $signature = 'logistics:db:slow-queries
        {--limit=20 : Cuántas consultas mostrar}
        {--order=total : Criterio de orden: total (tiempo acumulado) o mean (tiempo medio)}';

    protected $description = 'Muestra el top-N de pg_stat_statements por tiempo total o medio (diagnóstico de consultas lentas).';

    public function handle(): int
    {
        if (!$this->extensionAvailable()) {
            $this->warn('pg_stat_statements no está disponible en esta base. Habilitala en el servidor '
                .'(shared_preload_libraries + CREATE EXTENSION) para usar este diagnóstico.');

            // Degradación elegante: no es un fallo del comando, solo falta la extensión.
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $orderColumn = $this->option('order') === 'mean' ? 'mean_exec_time' : 'total_exec_time';

        try {
            $rows = DB::select(
                'select queryid, query, calls, '
                .'round(total_exec_time::numeric, 2) as total_ms, '
                .'round(mean_exec_time::numeric, 2) as mean_ms, '
                .'rows '
                .'from pg_stat_statements '
                ."order by {$orderColumn} desc "
                .'limit ?',
                [$limit],
            );
        } catch (Throwable $e) {
            $this->warn('No se pudo consultar pg_stat_statements: '.$e->getMessage());

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->info('pg_stat_statements no tiene registros todavía.');
        } else {
            $this->table(
                ['calls', 'total_ms', 'mean_ms', 'rows', 'query'],
                array_map(static function (object $r): array {
                    /** @var array<string, scalar|null> $row */
                    $row = (array) $r;
                    $query = (string) ($row['query'] ?? '');

                    return [
                        (string) ($row['calls'] ?? ''),
                        (string) ($row['total_ms'] ?? ''),
                        (string) ($row['mean_ms'] ?? ''),
                        (string) ($row['rows'] ?? ''),
                        // Recorta la consulta para que la tabla sea legible en la terminal.
                        mb_strlen($query) > 120 ? mb_substr($query, 0, 117).'...' : $query,
                    ];
                }, $rows),
            );
        }

        return self::SUCCESS;
    }

    /**
     * ¿Está instalada la extensión pg_stat_statements? Tolera errores de driver/motor (p. ej. SQLite en
     * algunos tests) devolviendo false en vez de propagar la excepción.
     */
    private function extensionAvailable(): bool
    {
        try {
            return DB::selectOne("select 1 as ok from pg_extension where extname = 'pg_stat_statements'") !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
