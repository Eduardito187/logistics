<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;

/**
 * Ola 4 — Simulacro de restauración (restore drill): verifica que el ciclo backup→restore funciona.
 *
 * Ejecuta las verificaciones de integridad SIN restaurar realmente (no destructivo):
 *   1. Verifica que el archivo de backup existe y es legible.
 *   2. Valida el formato con `pg_restore --list` (solo lista las secciones, no toca datos).
 *   3. Verifica que las tablas críticas aparecen en el TOC del dump.
 *   4. Reporta el tamaño del archivo y la fecha de modificación.
 *
 * Es la "HA del backup": de nada sirve tener dumps si nunca se verifica que se pueden restaurar.
 * El scheduler puede correrlo semanalmente; un fallo emite un log de error para las alertas.
 *
 * Para un restore REAL, usar `php artisan backup:restore` (skill db-backup).
 */
final class RestoreDrillCommand extends Command
{
    protected $signature = 'logistics:restore-drill
        {path? : Path to the .dump file to verify (default: latest in backups volume)}
        {--tables= : Comma-separated critical tables to verify in the TOC (defaults to all critical)}';

    protected $description = 'Verify that a backup dump is valid and restorable (non-destructive drill)';

    /**
     * Tablas críticas que DEBEN aparecer en el dump.
     *
     * @var list<string>
     */
    private const CRITICAL_TABLES = [
        'users',
        'shipments',
        'execution_deliveries',
        'execution_scan_events',
        'outbox_messages',
        'fleet_vehicles',
        'fleet_drivers',
        'telemetry_pings',
        'tracking_shipments',
    ];

    public function handle(DatabaseManager $db): int
    {
        $connection = $db->connection();
        if ($connection->getDriverName() !== 'pgsql') {
            $this->warn('logistics:restore-drill solo aplica a PostgreSQL; se omite.');

            return self::SUCCESS;
        }

        // Cada paso imprime su propio error; null/false corta la cadena sin ejecutar los siguientes.
        $path = $this->locateBackup();
        $toc = $path !== null && $this->isReadableDump($path) ? $this->readToc($path) : null;
        if ($toc === null || !$this->hasCriticalTables($toc)) {
            return self::FAILURE;
        }

        $this->info('Simulacro de restauración EXITOSO. El backup es válido y restaurable.');

        return self::SUCCESS;
    }

    /** Ubica el dump a verificar; null (con el error ya impreso) si no hay ninguno. */
    private function locateBackup(): ?string
    {
        $path = $this->resolvePath();
        if ($path === null) {
            $this->error('No se encontró un archivo de backup para verificar.');
        }

        return $path;
    }

    /**
     * Paso 1: verifica que el archivo existe y no está vacío, reportando tamaño y fecha de modificación.
     * Devuelve false (con el error ya impreso) si no sirve para continuar.
     */
    private function isReadableDump(string $path): bool
    {
        $this->info(sprintf('Verificando backup: %s', $path));

        if (!file_exists($path)) {
            $this->error(sprintf('El archivo no existe: %s', $path));

            return false;
        }

        $size = filesize($path);
        $modified = date('Y-m-d H:i:s', (int) filemtime($path));
        $this->line(sprintf('  Tamaño: %s | Última modificación: %s', $this->humanSize($size ?: 0), $modified));

        if ($size === false || $size === 0) {
            $this->error('El archivo está vacío.');

            return false;
        }

        return true;
    }

    /**
     * Paso 2: valida el formato con `pg_restore --list` y devuelve el TOC del dump; null (con el error ya
     * impreso) si pg_restore falla.
     */
    private function readToc(string $path): ?string
    {
        $tocFile = tempnam(sys_get_temp_dir(), 'restore_drill_');
        $exitCode = 0;
        $output = [];
        exec(sprintf('pg_restore --list %s 2>&1', escapeshellarg($path)), $output, $exitCode);
        @unlink((string) $tocFile);

        if ($exitCode !== 0) {
            $this->error('pg_restore --list falló: el archivo puede estar corrupto.');
            $this->line(implode("\n", array_slice($output, 0, 10)));

            return null;
        }

        $this->info('  pg_restore --list: OK (formato válido).');

        return implode("\n", $output);
    }

    /**
     * Paso 3: verifica que las tablas críticas aparecen en el TOC. Devuelve false (con el error ya impreso)
     * si falta alguna.
     */
    private function hasCriticalTables(string $toc): bool
    {
        $tables = $this->resolveTables();
        $missing = [];
        foreach ($tables as $table) {
            if (!str_contains($toc, $table)) {
                $missing[] = $table;
            }
        }

        if ($missing !== []) {
            $this->error(sprintf('Tablas críticas ausentes en el dump: %s', implode(', ', $missing)));

            return false;
        }

        $this->info(sprintf('  %d tabla(s) crítica(s) presentes en el dump.', count($tables)));

        return true;
    }

    private function resolvePath(): ?string
    {
        $explicit = $this->argument('path');
        if ($explicit !== null && is_string($explicit) && trim($explicit) !== '') {
            return $explicit;
        }

        // Buscar el último dump en las ubicaciones conocidas.
        $searchDirs = [
            storage_path('app/backups'),
            '/backups',
        ];

        foreach ($searchDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $files = glob($dir.'/*.dump');
            if ($files === false || $files === []) {
                continue;
            }

            // El más reciente por fecha de modificación.
            usort($files, static fn (string $a, string $b): int => (int) filemtime($b) - (int) filemtime($a));

            return $files[0];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function resolveTables(): array
    {
        $option = $this->option('tables');
        $raw = is_string($option) ? $option : '';
        if (trim($raw) === '') {
            return self::CRITICAL_TABLES;
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $t): bool => $t !== ''));
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $factor < count($units) - 1) {
            $size /= 1024;
            $factor++;
        }

        return sprintf('%.1f %s', $size, $units[$factor]);
    }
}
