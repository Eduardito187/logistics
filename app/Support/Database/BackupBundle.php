<?php

declare(strict_types=1);

namespace App\Support\Database;

use DateTimeInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Bundle de backup: un `.tar` (sin comprimir: el dump ya va comprimido) con exactamente dos miembros:
 *
 *   manifest.json   — formato, fecha, entorno, commit, versiones, inventario de estructura y conteo de filas
 *   database.dump   — pg_dump --format=custom completo
 *
 * Construcción de comandos PURA; empaquetar/desempaquetar va por `tar` vía Process. El bundle se escribe
 * con sufijo `.tmp` y se renombra al final: nunca queda un archivo a medias con el nombre final.
 */
final class BackupBundle
{
    public const MANIFEST = 'manifest.json';

    public const DUMP = 'database.dump';

    /** Ruta por defecto: `<BACKUP_PATH>/logistics-<YYYYmmdd-HHMMSS>.tar`. */
    public static function defaultPath(string $directory, DateTimeInterface $at): string
    {
        return rtrim($directory, '/\\').'/logistics-'.$at->format('Ymd-His').'.tar';
    }

    /** @return list<string> */
    public static function packCommand(string $bundle, string $workDir): array
    {
        return ['tar', '--force-local', '-cf', $bundle, '-C', $workDir, self::MANIFEST, self::DUMP];
    }

    /**
     * Extrae SOLO los dos miembros conocidos (cualquier otro contenido del tar se ignora).
     *
     * @return list<string>
     */
    public static function unpackCommand(string $bundle, string $workDir): array
    {
        return ['tar', '--force-local', '-xf', $bundle, '-C', $workDir, '--no-same-owner', '--no-same-permissions', self::MANIFEST, self::DUMP];
    }

    /** Directorio de trabajo temporal, junto al bundle/destino (mismo filesystem). */
    public static function makeWorkDir(string $near): string
    {
        $dir = rtrim(dirname($near), '/\\').'/.backup-work-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    public static function pack(string $bundle, string $workDir): void
    {
        self::tar(self::packCommand($bundle, $workDir), 'empaquetar');
    }

    /**
     * Extrae el bundle en `$workDir` y devuelve el manifest validado.
     *
     * @return array<string, mixed>
     */
    public static function unpack(string $bundle, string $workDir): array
    {
        self::tar(self::unpackCommand($bundle, $workDir), 'desempaquetar');

        return self::readManifest($workDir.'/'.self::MANIFEST);
    }

    /**
     * Lee y valida un manifest (bundle o `<dump>.manifest.json` de db:backup).
     *
     * @return array<string, mixed>
     */
    public static function readManifest(string $path): array
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        $manifest = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($manifest) || !is_array($manifest['database'] ?? null)) {
            throw new BackupException("Manifest inválido o ilegible: {$path}");
        }
        if (($manifest['format'] ?? null) !== BackupManifest::FORMAT) {
            throw new BackupException('Formato de manifest no soportado: '.json_encode($manifest['format'] ?? null).' (se espera '.BackupManifest::FORMAT.').');
        }

        /** @var array<string, mixed> $manifest */
        return $manifest;
    }

    /**
     * Manifest completo (formato 1).
     *
     * @param  array<string, mixed> $database sección producida por DatabaseBackupService::dump()
     * @return array<string, mixed>
     */
    public static function manifest(array $database, DateTimeInterface $at, ?string $gitCommit): array
    {
        return [
            'format' => BackupManifest::FORMAT,
            'kind' => 'postgresql-database',
            'scope' => 'Base PostgreSQL completa (estructura + datos). NO incluye archivos subidos, volúmenes, contenedores ni imágenes.',
            'created_at' => $at->format(DATE_ATOM),
            'app_env' => (string) app()->environment(),
            'git_commit' => $gitCommit,
            'database' => $database,
        ];
    }

    /** @param array<string, mixed> $manifest */
    public static function writeManifest(string $path, array $manifest): void
    {
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }

    /** @param list<string> $command */
    private static function tar(array $command, string $what): void
    {
        $timeout = config('backup.timeout', 7200);
        $result = Process::timeout(is_numeric($timeout) ? (int) $timeout : 7200)->run($command);

        if (!$result->successful()) {
            throw new BackupException("No se pudo {$what} el bundle (tar): ".trim($result->errorOutput()));
        }
    }
}
