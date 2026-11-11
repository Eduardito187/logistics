<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Database\BackupBundle;
use Illuminate\Console\Command;

/**
 * Importa POR COMPLETO un `.dump` de `db:backup` sobre la base PostgreSQL con el MISMO camino seguro que
 * `backup:restore`: restaura en una base nueva (`<db>_restore_<ts>`, TEMPLATE template0, sin `--clean`),
 * verifica y recién entonces hace el swap (la anterior queda como `<db>_prev_<ts>`).
 *
 * Verificación: si existe `<archivo>.manifest.json` (db:backup lo escribe), compara estructura + conteo de
 * filas; si no (dump viejo), verificación ESTRUCTURAL: toda tabla del TOC del dump debe existir.
 *
 *   docker compose exec -T app php artisan db:restore storage/app/backups/backup-....dump
 *   docker compose exec -T app php artisan db:restore backup.dump --force   # sin preguntar (CI/scripts)
 */
final class DatabaseRestoreCommand extends Command
{
    use BackupCommandSupport;

    protected $signature = 'db:restore
        {file : Ruta del archivo de backup (.dump) a importar}
        {--connection= : Conexión destino; por defecto la conexión por defecto}
        {--host= : Host de Postgres (override; por defecto DB_DIRECT_HOST)}
        {--port= : Puerto de Postgres (override; por defecto DB_DIRECT_PORT)}
        {--force : No pedir confirmación (y permitir la restauración en producción)}';

    protected $description = 'Importa por completo un backup (.dump) de forma segura (base nueva + verificación + swap).';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (!is_file($file)) {
            $this->error("No existe el archivo de backup: {$file}");

            return self::FAILURE;
        }

        return $this->restoreDump(function (string $workDir) use ($file): array {
            $manifestPath = $file.'.manifest.json';
            if (!is_file($manifestPath)) {
                $this->warn('Sin manifest al lado del dump: se hará verificación estructural (tablas del TOC), sin conteo de filas.');

                return [$file, null];
            }

            $database = BackupBundle::readManifest($manifestPath)['database'];
            /** @var array<string, mixed> $database */
            if (is_string($database['sha256'] ?? null) && hash_file('sha256', $file) !== $database['sha256']) {
                throw new \RuntimeException('El sha256 del dump no coincide con su manifest: archivo corrupto.');
            }

            return [$file, $database];
        }, $file, (bool) $this->option('force'));
    }
}
