<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Database\BackupBundle;
use App\Support\Database\DatabaseBackupService;
use App\Support\Database\PostgresBackup;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Utilidades compartidas por los comandos de backup (backup:*, db:backup, db:restore): opciones, servicio
 * con salida a consola, tabla de diferencias y guard de producción.
 *
 * @phpstan-require-extends Command
 */
trait BackupCommandSupport
{
    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function backupService(): DatabaseBackupService
    {
        $connection = $this->stringOption('connection') ?? (string) config('database.default');

        return DatabaseBackupService::forConnection(
            $connection,
            $this->stringOption('host'),
            $this->stringOption('port'),
            fn (string $line) => $this->line($line),
        );
    }

    /** @param list<array{item: string, expected: string, actual: string}> $diffs */
    private function printDiffs(array $diffs): void
    {
        $this->error(count($diffs).' diferencia(s):');
        $this->table(['Item', 'Expected', 'Actual'], array_map(static fn (array $d): array => [$d['item'], $d['expected'], $d['actual']], $diffs));
    }

    /** @param array<string, int> $summary */
    private function printSummary(array $summary): void
    {
        $this->table(['Object', 'Count'], array_map(static fn (string $k, int $v): array => [$k, (string) $v], array_keys($summary), array_values($summary)));
    }

    /** Confirmación del operador (saltable con --force). True = seguir. */
    private function confirmReplace(string $database, bool $force): bool
    {
        if (!$force && !$this->confirm("Esto REEMPLAZARÁ por completo la base '{$database}'. ¿Continuar?", false)) {
            $this->info('Cancelado. No se tocó la base.');

            return false;
        }

        return true;
    }

    /**
     * Flujo común de restore seguro (backup:restore y db:restore): guard + confirmación, preparación del
     * dump, restore en scratch + verificación + swap, y reporte. `$prepare` recibe un directorio de trabajo
     * y devuelve [ruta del dump, sección `database` del manifest o null si no hay manifest].
     *
     * @param callable(string): array{0: string, 1: array<string, mixed>|null} $prepare
     */
    private function restoreDump(callable $prepare, string $near, bool $force): int
    {
        $database = $this->restoreTargetDatabase($force);
        if ($database === null) {
            return self::FAILURE;
        }
        if (!$this->confirmReplace($database, $force)) {
            return self::SUCCESS;
        }

        $result = $this->runSafeRestore($prepare, $near, $database);

        return $result === null ? self::FAILURE : $this->reportRestore($result, $database);
    }

    /**
     * Nombre de la base viva destino, o null (con el error ya impreso) si la conexión no sirve o si es
     * producción sin --force.
     */
    private function restoreTargetDatabase(bool $force): ?string
    {
        if (app()->environment('production') && !$force) {
            $this->error('Restauración en PRODUCCIÓN bloqueada. Volvé a correr con --force si es intencional.');

            return null;
        }

        try {
            return PostgresBackup::forConnection($this->stringOption('connection') ?? (string) config('database.default'))
                ->endpoint($this->stringOption('host'), $this->stringOption('port'))->database;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return null;
        }
    }

    /**
     * Prepara el dump y corre el restore seguro (scratch + verificación + swap). Null (con el error ya
     * impreso) si algo falló antes de terminar; el directorio de trabajo se borra siempre.
     *
     * @param  callable(string): array{0: string, 1: array<string, mixed>|null}                                                                                                                                                 $prepare
     * @return array{live: string, prev: string|null, diffs: list<array{item: string, expected: string, actual: string}>, final_diffs: list<array{item: string, expected: string, actual: string}>, verified_counts: bool}|null
     */
    private function runSafeRestore(callable $prepare, string $near, string $database): ?array
    {
        $workDir = BackupBundle::makeWorkDir($near);

        try {
            [$dump, $expected] = $prepare($workDir);
            $service = $this->backupService();
            $service->assertClientVersions();

            return $service->safeRestore($dump, $expected, new DateTimeImmutable);
        } catch (Throwable $e) {
            $this->error('Restauración FALLIDA: '.$e->getMessage());
            $this->line("La base viva '{$database}' no fue reemplazada (salvo que el mensaje diga lo contrario).");

            return null;
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    /**
     * Reporta el resultado del restore: aborto por diferencias (la viva no se tocó), swap + rollback, y
     * verificación final.
     *
     * @param array{live: string, prev: string|null, diffs: list<array{item: string, expected: string, actual: string}>, final_diffs: list<array{item: string, expected: string, actual: string}>, verified_counts: bool} $result
     */
    private function reportRestore(array $result, string $database): int
    {
        if ($result['diffs'] !== []) {
            $this->printDiffs($result['diffs']);
            $this->error("Restauración ABORTADA: la base restaurada no coincide. La base viva '{$database}' NO se tocó.");

            return self::FAILURE;
        }

        $this->info("Swap completo: '{$result['live']}' es ahora la base restaurada.");
        $this->printRollback($result['live'], $result['prev']);

        if ($result['final_diffs'] !== []) {
            $this->printDiffs($result['final_diffs']);
            $this->error('La verificación FINAL encontró diferencias (¿escrituras concurrentes tras el swap? ¿app sin `down`?). Revisá y, si hace falta, volvé atrás con las instrucciones de arriba.');

            return self::FAILURE;
        }

        $this->info('Verificación final OK: la base viva es la restaurada'.($result['verified_counts'] ? ' y estructura + conteos coinciden con el manifest.' : ' (sin manifest: verificación estructural por TOC).'));

        return self::SUCCESS;
    }

    /** Instrucciones de rollback y limpieza tras un swap. */
    private function printRollback(string $live, ?string $prev): void
    {
        if ($prev === null) {
            $this->line("No existía una base '{$live}' previa: no hay nada que revertir.");

            return;
        }

        $this->newLine();
        $this->line("La base anterior quedó como '{$prev}' (ALLOW_CONNECTIONS false). Desde psql en la base 'postgres':");
        $this->line("  Volver atrás (con la app en `down`; si el rename dice que la base está en uso: SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$live}';):");
        $this->line("    ALTER DATABASE \"{$live}\" RENAME TO \"{$live}_discarded\";");
        $this->line("    ALTER DATABASE \"{$prev}\" WITH ALLOW_CONNECTIONS true;");
        $this->line("    ALTER DATABASE \"{$prev}\" RENAME TO \"{$live}\";");
        $this->line('  Borrarla cuando ya no haga falta:');
        $this->line("    DROP DATABASE \"{$prev}\";");
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
