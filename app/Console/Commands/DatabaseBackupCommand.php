<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Database\BackupBundle;
use App\Support\Database\BackupException;
use App\Support\Database\DatabaseBackupService;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Backup COMPLETO de la base PostgreSQL (estructura + datos) a un `.dump` (pg_dump custom) más un
 * `<archivo>.manifest.json` al lado con el inventario de estructura y el conteo exacto de filas (mismo
 * snapshot que el dump). Comando de compatibilidad: el formato recomendado es el bundle de `backup:create`.
 * Solo lee la base. Va DIRECTO a Postgres (DB_DIRECT_HOST/PORT o --host/--port), no por PgBouncer.
 *
 *   docker compose exec -T app php artisan db:backup
 *   docker compose exec -T app php artisan db:backup --path=/var/www/html/storage/app/backups/manual.dump
 */
final class DatabaseBackupCommand extends Command
{
    use BackupCommandSupport;

    protected $signature = 'db:backup
        {--path= : Ruta del archivo de salida; por defecto <BACKUP_PATH>/backup-<base>-<timestamp>.dump}
        {--connection= : Conexión a respaldar; por defecto la conexión por defecto}
        {--host= : Host de Postgres (override; por defecto DB_DIRECT_HOST)}
        {--port= : Puerto de Postgres (override; por defecto DB_DIRECT_PORT)}';

    protected $description = 'Genera un backup completo (estructura + datos) de la base PostgreSQL a un .dump con manifest al lado.';

    public function handle(): int
    {
        $at = new DateTimeImmutable;
        $file = null;
        $tmp = null;

        try {
            $service = $this->backupService();
            $file = $this->resolvePath($service->endpoint()->database, $at);
            $tmp = $file.'.tmp';
            File::ensureDirectoryExists(dirname($file));

            $versions = $service->assertClientVersions();
            $this->info("Respaldando la base en {$file} …");

            @unlink($tmp);
            $database = $service->dump($tmp, $versions);
            $database['file'] = basename($file);
            if (!rename($tmp, $file)) {
                throw new BackupException("No se pudo renombrar {$tmp} a {$file}.");
            }
            BackupBundle::writeManifest($file.'.manifest.json', BackupBundle::manifest($database, $at, DatabaseBackupService::gitCommit()));
        } catch (Throwable $e) {
            if ($tmp !== null) {
                @unlink($tmp);
            }
            $this->error('Backup FALLIDO: '.$e->getMessage());

            return self::FAILURE;
        }

        /** @var array<string, int> $summary */
        $summary = $database['summary'];
        $this->printSummary($summary);
        $this->info('Backup completo. '.$this->humanSize((int) filesize($file)).' → '.$file);
        $this->line('Manifest: '.$file.'.manifest.json');

        return self::SUCCESS;
    }

    /** Ruta de salida: la del option, o una con timestamp bajo BACKUP_PATH. */
    private function resolvePath(string $database, DateTimeImmutable $at): string
    {
        $path = $this->stringOption('path');
        if ($path !== null) {
            return $path;
        }

        $directory = config('backup.path');
        $name = ($database !== '' ? $database : 'database').'-'.$at->format('Ymd-His');

        return rtrim(is_string($directory) ? $directory : storage_path('app/backups'), '/\\').'/backup-'.$name.'.dump';
    }
}
