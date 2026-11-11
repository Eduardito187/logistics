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
        $outcome = is_file($bundle) ? $this->verifyBundle($bundle) : $this->missingBundle($bundle);
        if ($outcome === null) {
            return self::FAILURE;
        }

        [$diffs, $database] = $outcome;
        if ($diffs !== []) {
            $this->printDiffs($diffs);
            $this->error('Verificación FALLIDA: el backup no coincide con su manifest.');

            return self::FAILURE;
        }

        $this->printSummary(BackupManifest::summary($database));
        $this->info('Verificación OK: estructura y conteo de filas idénticos al manifest.');

        return self::SUCCESS;
    }

    /** Bundle inexistente: imprime el error y no hay resultado. */
    private function missingBundle(string $bundle): null
    {
        $this->error("No existe el bundle: {$bundle}");

        return null;
    }

    /**
     * Desempaqueta, valida el sha256 y restaura en una base scratch comparando contra el manifest. Devuelve
     * [diferencias, sección `database` del manifest], o null (con el error ya impreso) si no pudo verificar.
     * La base scratch y el directorio de trabajo se eliminan siempre.
     *
     * @return array{0: list<array{item: string, expected: string, actual: string}>, 1: array<string, mixed>}|null
     */
    private function verifyBundle(string $bundle): ?array
    {
        $workDir = BackupBundle::makeWorkDir($bundle);
        $scratch = null;
        $service = null;

        try {
            $manifest = BackupBundle::unpack($bundle, $workDir);
            /** @var array<string, mixed> $database */
            $database = $manifest['database'];
            $dump = $workDir.'/'.BackupBundle::DUMP;

            $sha = (string) hash_file('sha256', $dump);
            $expectedSha = $database['sha256'] ?? null;
            if ($sha !== $expectedSha) {
                // Dump corrupto: no tiene sentido restaurarlo; la diferencia de sha256 ya es el veredicto.
                return [[['item' => 'database.dump sha256', 'expected' => is_string($expectedSha) ? $expectedSha : 'null', 'actual' => $sha]], $database];
            }

            $service = $this->backupService();
            $service->assertClientVersions();
            $scratch = BackupManifest::auxiliaryName($service->endpoint()->database, 'verify', new DateTimeImmutable);

            return [$service->restoreIntoScratch($dump, $scratch, $database), $database];
        } catch (Throwable $e) {
            $this->error('Verificación FALLIDA: '.$e->getMessage());

            return null;
        } finally {
            if ($service !== null && $scratch !== null) {
                $service->dropScratchQuietly($scratch);
                $this->line("Base scratch '{$scratch}' eliminada.");
            }
            File::deleteDirectory($workDir);
        }
    }
}
