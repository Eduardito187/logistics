<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Database\BackupBundle;
use App\Support\Database\DatabaseBackupService;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * Backup COMPLETO y VERIFICABLE de la base PostgreSQL: genera un bundle `.tar` con `database.dump`
 * (pg_dump custom, estructura + datos) y `manifest.json` (inventario de estructura + conteo exacto de filas
 * tomados en el MISMO snapshot que el dump). Solo lee la base. No incluye archivos subidos ni volúmenes.
 *
 *   docker compose exec -T app php artisan backup:create
 *   docker compose exec -T app php artisan backup:create --path=/backups/manual.tar
 *
 * Va DIRECTO a Postgres (DB_DIRECT_HOST/PORT, o --host/--port), nunca por PgBouncer.
 */
final class BackupCreateCommand extends Command
{
    use BackupCommandSupport;

    protected $signature = 'backup:create
        {--path= : Archivo .tar de salida; por defecto <BACKUP_PATH>/logistics-<YYYYmmdd-HHMMSS>.tar}
        {--connection= : Conexión a respaldar; por defecto la conexión por defecto}
        {--host= : Host de Postgres (override; por defecto DB_DIRECT_HOST)}
        {--port= : Puerto de Postgres (override; por defecto DB_DIRECT_PORT)}';

    protected $description = 'Genera un bundle de backup completo de la base (dump + manifest con estructura y conteo de filas).';

    public function handle(): int
    {
        $at = new DateTimeImmutable;
        $directory = config('backup.path');
        $bundle = $this->stringOption('path') ?? BackupBundle::defaultPath(is_string($directory) ? $directory : storage_path('app/backups'), $at);
        $tmp = $bundle.'.tmp';
        $workDir = null;

        try {
            File::ensureDirectoryExists(dirname($bundle));
            $workDir = BackupBundle::makeWorkDir($bundle);

            $service = $this->backupService();
            $versions = $service->assertClientVersions();
            $this->line("Servidor PostgreSQL {$versions['server']} · {$versions['pg_dump']}");

            $database = $service->dump($workDir.'/'.BackupBundle::DUMP, $versions);
            BackupBundle::writeManifest($workDir.'/'.BackupBundle::MANIFEST, BackupBundle::manifest($database, $at, DatabaseBackupService::gitCommit()));

            @unlink($tmp);
            BackupBundle::pack($tmp, $workDir);
            if (!rename($tmp, $bundle)) {
                throw new RuntimeException("No se pudo renombrar {$tmp} a {$bundle}.");
            }
        } catch (Throwable $e) {
            @unlink($tmp);
            $this->error('Backup FALLIDO: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if ($workDir !== null) {
                File::deleteDirectory($workDir);
            }
        }

        /** @var array<string, int> $summary */
        $summary = $database['summary'];
        $this->printSummary($summary);
        $this->info('Backup completo. '.$this->humanSize((int) filesize($bundle)).' → '.$bundle);
        $this->line('Verificalo sin tocar nada con: php artisan backup:verify '.$bundle);

        return self::SUCCESS;
    }
}
