<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ola 4 — Preparación para deploy sin downtime (pre-deploy hook).
 *
 * Ejecuta las acciones necesarias ANTES del rolling restart de los contenedores:
 *   1. Señaliza a los workers que drenen y se reciclen (`queue:restart`).
 *   2. Limpia caches del scheduler para evitar que el outbox pump se congele
 *      (ver outbox-scheduler-overlap-lock).
 *   3. Valida que la conexión a la base funciona (no deployar si PG está caído).
 *   4. Valida que Valkey funciona (no deployar si la caché/colas están caídas).
 *   5. Reporta el estado de las migraciones pendientes (informativo, no bloquea).
 *
 * Se invoca como paso del pipeline de deploy, antes del rollout:
 *   docker run --rm --env-file .env.prod <image> php artisan logistics:pre-deploy
 *
 * Si cualquier validación crítica falla, sale con código 1 para detener el deploy.
 */
final class PreDeployCommand extends Command
{
    protected $signature = 'logistics:pre-deploy
        {--skip-queue-restart : Do not signal queue:restart (useful when workers deploy separately)}
        {--skip-db-check : Do not verify database connectivity}';

    protected $description = 'Pre-deploy validation and preparation for zero-downtime deployments';

    public function handle(): int
    {
        $this->info('=== Pre-deploy check (Ola 4) ===');
        $failed = false;

        // 1. Verificar conectividad a PostgreSQL.
        if (!(bool) $this->option('skip-db-check')) {
            $this->line('Verificando PostgreSQL...');
            try {
                DB::connection()->getPdo();
                $version = DB::connection()->selectOne('SELECT version() as v');
                $this->info(sprintf('  PostgreSQL OK: %s', substr((string) ($version->v ?? '?'), 0, 80)));
            } catch (\Throwable $e) {
                $this->error(sprintf('  PostgreSQL FALLO: %s', $e->getMessage()));
                $failed = true;
            }
        }

        // 2. Verificar Valkey.
        $this->line('Verificando Valkey...');
        try {
            Cache::store('redis')->put('deploy:check', 'ok', 10);
            $val = Cache::store('redis')->get('deploy:check');
            if ($val !== 'ok') {
                throw new \RuntimeException('Lectura de Valkey no coincide con la escritura.');
            }
            $this->info('  Valkey OK.');
        } catch (\Throwable $e) {
            $this->error(sprintf('  Valkey FALLO: %s', $e->getMessage()));
            $failed = true;
        }

        if ($failed) {
            $this->error('Pre-deploy FALLIDO. Abortando el deploy.');

            return self::FAILURE;
        }

        // 3. Señalizar a los workers.
        if (!(bool) $this->option('skip-queue-restart')) {
            $this->line('Señalizando queue:restart...');
            $this->call('queue:restart');
            $this->info('  Workers señalizados; drenarán el job actual y se reciclarán.');
        }

        // 4. Limpiar caches del scheduler (evita congelamiento del outbox pump).
        $this->line('Limpiando caches del scheduler...');
        $this->callSilently('schedule:clear-cache');
        $this->info('  Caches del scheduler limpios.');

        // 5. Migraciones pendientes (informativo).
        $this->line('Migraciones pendientes:');
        $this->call('migrate:status', ['--pending' => true]);

        $this->newLine();
        $this->info('=== Pre-deploy OK. Proceder con el rollout. ===');

        return self::SUCCESS;
    }
}
