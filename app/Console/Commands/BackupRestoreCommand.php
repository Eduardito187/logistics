<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Database\BackupBundle;
use App\Support\Database\BackupException;
use Illuminate\Console\Command;

/**
 * Restore SEGURO por SWAP de un bundle de backup:create sobre la base de la conexión:
 *
 *  1. restaura en una base NUEVA `<db>_restore_<ts>` (TEMPLATE template0, sin --clean);
 *  2. compara estructura + conteo de filas contra el manifest; si difiere, aborta y dropea la scratch
 *     (la base viva NO se tocó);
 *  3. termina las conexiones y renombra la viva a `<db>_prev_<ts>` y la nueva a `<db>`;
 *  4. verificación final sobre el nombre vivo.
 *
 * DESTRUCTIVO para la base viva (queda como `_prev_` para rollback): pide confirmación (saltable con
 * --force) y en producción exige --force. Conviene `php artisan down` y frenar workers antes.
 *
 *   docker compose exec -T app php artisan backup:restore storage/app/backups/logistics-....tar
 */
final class BackupRestoreCommand extends Command
{
    use BackupCommandSupport;

    protected $signature = 'backup:restore
        {bundle : Bundle .tar generado por backup:create}
        {--connection= : Conexión destino; por defecto la conexión por defecto}
        {--host= : Host de Postgres (override; por defecto DB_DIRECT_HOST)}
        {--port= : Puerto de Postgres (override; por defecto DB_DIRECT_PORT)}
        {--force : No pedir confirmación (y permitir la restauración en producción)}';

    protected $description = 'Restaura un bundle de backup de forma segura (base nueva + verificación + swap; la anterior queda para rollback).';

    public function handle(): int
    {
        $bundleArg = $this->argument('bundle');
        $bundle = is_string($bundleArg) ? $bundleArg : '';
        if (!is_file($bundle)) {
            $this->error("No existe el bundle: {$bundle}");

            return self::FAILURE;
        }

        return $this->restoreDump(static function (string $workDir) use ($bundle): array {
            $manifest = BackupBundle::unpack($bundle, $workDir);
            /** @var array<string, mixed> $database */
            $database = $manifest['database'];
            $dump = $workDir.'/'.BackupBundle::DUMP;

            if (hash_file('sha256', $dump) !== ($database['sha256'] ?? null)) {
                throw new BackupException('El sha256 de database.dump no coincide con el manifest: bundle corrupto.');
            }

            return [$dump, $database];
        }, $bundle, (bool) $this->option('force'));
    }
}
