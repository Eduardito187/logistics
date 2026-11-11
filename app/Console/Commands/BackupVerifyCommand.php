<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Database\BackupBundle;
use App\Support\Database\BackupManifest;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Verificación NO destructiva de un bundle: valida el sha256 del dump, lo restaura en una base SCRATCH nueva
 * (`<db>_verify_<ts>`, TEMPLATE template0) del mismo servidor, compara estructura y conteo de filas contra el
 * manifest y dropea la scratch SIEMPRE (también si falla). Exit ≠0 ante cualquier diferencia.
 *
 *   docker compose exec -T app php artisan backup:verify storage/app/backups/logistics-....tar
 */
final class BackupVerifyCommand extends Command
{
    use BackupCommandSupport;

    protected $signature = 'backup:verify
        {bundle : Bundle .tar generado por backup:create}
        {--connection= : Conexión cuyo servidor aloja la base scratch; por defecto la conexión por defecto}
        {--host= : Host de Postgres (override; por defecto DB_DIRECT_HOST)}
        {--port= : Puerto de Postgres (override; por defecto DB_DIRECT_PORT)}';

    protected $description = 'Verifica un bundle de backup restaurándolo en una base scratch y comparando contra su manifest (no destructivo).';

    public function handle(): int
    {
        $bundle = (string) $this->argument('bundle');
        if (!is_file($bundle)) {
            $this->error("No existe el bundle: {$bundle}");

            return self::FAILURE;
        }

        $workDir = BackupBundle::makeWorkDir($bundle);
        $scratch = null;
        $service = null;
        $database = [];

        try {
            $manifest = BackupBundle::unpack($bundle, $workDir);
            /** @var array<string, mixed> $database */
            $database = $manifest['database'];
            $dump = $workDir.'/'.BackupBundle::DUMP;

            $diffs = [];
            $sha = (string) hash_file('sha256', $dump);
            $expectedSha = $database['sha256'] ?? null;
            if ($sha !== $expectedSha) {
                $diffs[] = ['item' => 'database.dump sha256', 'expected' => is_string($expectedSha) ? $expectedSha : 'null', 'actual' => $sha];
            }

            if ($diffs === []) {
                $service = $this->backupService();
                $service->assertClientVersions();
                $scratch = BackupManifest::auxiliaryName($service->endpoint()->database, 'verify', new DateTimeImmutable);
                $diffs = $service->restoreIntoScratch($dump, $scratch, $database);
            }
        } catch (Throwable $e) {
            $this->error('Verificación FALLIDA: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if ($service !== null && $scratch !== null) {
                $service->dropScratchQuietly($scratch);
                $this->line("Base scratch '{$scratch}' eliminada.");
            }
            File::deleteDirectory($workDir);
        }

        if ($diffs !== []) {
            $this->printDiffs($diffs);
            $this->error('Verificación FALLIDA: el backup no coincide con su manifest.');

            return self::FAILURE;
        }

        $this->printSummary(BackupManifest::summary($database));
        $this->info('Verificación OK: estructura y conteo de filas idénticos al manifest.');

        return self::SUCCESS;
    }
}
