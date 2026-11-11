<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Database\PostgresBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Backup COMPLETO de la base PostgreSQL (esquema + datos) a un archivo custom comprimido, restaurable con
 * `db:restore`. No toca la base: solo lee. Pensado para correr dentro del contenedor:
 *
 *   docker compose exec -T app php artisan db:backup
 *   docker compose exec -T app php artisan db:backup --path=/var/www/html/storage/app/backups/manual.dump
 *   docker compose exec -T app php artisan db:backup --host=postgres --port=5432   # directo al Postgres real
 *
 * Requiere `pg_dump` (paquete postgresql-client) en la imagen. Si falta, avisa claro y termina con error.
 */
final class DatabaseBackupCommand extends Command
{
    protected $signature = 'db:backup
        {--path= : Ruta del archivo de salida; por defecto storage/app/backups/backup-<base>-<timestamp>.dump}
        {--connection= : Conexión a respaldar; por defecto la conexión por defecto}
        {--host= : Host de Postgres (override; útil para saltar PgBouncer y apuntar al Postgres real)}
        {--port= : Puerto de Postgres (override)}';

    protected $description = 'Genera un backup completo (esquema + datos) de la base PostgreSQL a un archivo restaurable.';

    public function handle(): int
    {
        $connection = $this->stringOption('connection') ?? (string) config('database.default');
        $host = $this->stringOption('host');
        $port = $this->stringOption('port');

        try {
            $backup = PostgresBackup::forConnection($connection);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $file = $this->resolvePath($backup->target($host, $port)['database']);
        File::ensureDirectoryExists(dirname($file));

        $this->info("Respaldando la base en {$file} …");

        try {
            $result = $backup->run($backup->dumpCommand($file, $host, $port), $host, $port);
        } catch (Throwable $e) {
            $this->error('No se pudo ejecutar pg_dump: '.$e->getMessage());
            $this->line('¿Está instalado postgresql-client (pg_dump) en el contenedor?');

            return self::FAILURE;
        }

        if (!$result->successful()) {
            $this->error('pg_dump terminó con error:');
            $this->line(trim($result->errorOutput()) !== '' ? $result->errorOutput() : $result->output());

            return self::FAILURE;
        }

        $size = is_file($file) ? (int) filesize($file) : 0;
        $this->info('Backup completo. '.$this->humanSize($size).' → '.$file);

        return self::SUCCESS;
    }

    /** Ruta de salida: la del option, o una con timestamp bajo storage/app/backups. */
    private function resolvePath(string $database): string
    {
        $path = $this->stringOption('path');
        if ($path !== null) {
            return $path;
        }

        $name = ($database !== '' ? $database : 'database').'-'.date('Ymd-His');

        return storage_path('app/backups/backup-'.$name.'.dump');
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $power), 2).' '.$units[$power];
    }
}
