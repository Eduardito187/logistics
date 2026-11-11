<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Database\PostgresBackup;
use Illuminate\Console\Command;
use Throwable;

/**
 * Importa POR COMPLETO un backup de `db:backup` sobre la base PostgreSQL, REEMPLAZANDO su contenido
 * (`pg_restore --clean --if-exists`, en una sola transacción). Operación DESTRUCTIVA: pide confirmación
 * explícita (saltable con --force para automatización) y bloquea en producción salvo --force.
 *
 *   docker compose exec -T app php artisan db:restore storage/app/backups/backup-....dump
 *   docker compose exec -T app php artisan db:restore backup.dump --force   # sin preguntar (CI/scripts)
 *
 * Requiere `pg_restore` (postgresql-client) en la imagen. El guard de base de desarrollo/prod lo da el
 * operador: por eso en producción exige --force a propósito.
 */
final class DatabaseRestoreCommand extends Command
{
    protected $signature = 'db:restore
        {file : Ruta del archivo de backup (.dump) a importar}
        {--connection= : Conexión destino; por defecto la conexión por defecto}
        {--host= : Host de Postgres (override; útil para saltar PgBouncer)}
        {--port= : Puerto de Postgres (override)}
        {--force : No pedir confirmación (y permitir la restauración en producción)}';

    protected $description = 'Importa por completo un backup (.dump) sobre la base PostgreSQL, reemplazando su contenido.';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (!is_file($file)) {
            $this->error("No existe el archivo de backup: {$file}");

            return self::FAILURE;
        }

        $connection = $this->stringOption('connection') ?? (string) config('database.default');
        $host = $this->stringOption('host');
        $port = $this->stringOption('port');
        $force = (bool) $this->option('force');

        try {
            $backup = PostgresBackup::forConnection($connection);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $database = $backup->target($host, $port)['database'];

        // Guard de producción: solo con --force (evita pisar la base viva por accidente).
        if (app()->environment('production') && !$force) {
            $this->error('Restauración en PRODUCCIÓN bloqueada. Volvé a correr con --force si es intencional.');

            return self::FAILURE;
        }

        // Confirmación: la restauración REEMPLAZA todo el contenido de la base destino.
        if (!$force && !$this->confirm("Esto REEMPLAZARÁ por completo la base '{$database}'. ¿Continuar?", false)) {
            $this->info('Cancelado. No se tocó la base.');

            return self::SUCCESS;
        }

        $this->info("Importando {$file} sobre la base '{$database}' …");

        try {
            $result = $backup->run($backup->restoreCommand($file, $host, $port), $host, $port);
        } catch (Throwable $e) {
            $this->error('No se pudo ejecutar pg_restore: '.$e->getMessage());
            $this->line('¿Está instalado postgresql-client (pg_restore) en el contenedor?');

            return self::FAILURE;
        }

        if (!$result->successful()) {
            $this->error('pg_restore terminó con error:');
            $this->line(trim($result->errorOutput()) !== '' ? $result->errorOutput() : $result->output());

            return self::FAILURE;
        }

        $this->info("Restauración completa sobre la base '{$database}'.");

        return self::SUCCESS;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
