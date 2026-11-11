<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Process;

/**
 * Backup/restore COMPLETO de la base PostgreSQL (estructura + datos) con las herramientas nativas
 * `pg_dump`/`pg_restore`. La construcción de los comandos es PURA (sin I/O), para poder testearla sin
 * ejecutar nada; la ejecución real va por el `Process` de Laravel, con la clave en `PGPASSWORD` (nunca en
 * argv, que es visible en `ps`).
 *
 * El dump usa el formato CUSTOM de Postgres (`-Fc`, comprimido). La restauración NUNCA usa `--clean`: se
 * restaura siempre en una base NUEVA creada desde `template0` (ver DatabaseBackupService), así no choca con
 * objetos preexistentes (p. ej. las extensiones `postgis_topology`/`postgis_tiger_geocoder` que crea la
 * imagen de PostGIS, que hacían fallar el `DROP EXTENSION postgis` del `--clean`).
 *
 * El endpoint por defecto es DIRECTO a Postgres (DB_DIRECT_HOST/DB_DIRECT_PORT), no PgBouncer.
 */
final class PostgresBackup
{
    /**
     * @param array<string, mixed> $config configuración de la conexión (config/database.php)
     */
    public function __construct(
        private readonly array $config,
        private readonly ?string $directHost = null,
        private readonly ?string $directPort = null,
    ) {}

    /**
     * Construye el backup para una conexión del `config/database.php`. Falla claro si no es PostgreSQL:
     * este mecanismo solo respalda pgsql.
     */
    public static function forConnection(string $connection): self
    {
        $config = config("database.connections.{$connection}");

        if (!is_array($config) || ($config['driver'] ?? null) !== 'pgsql') {
            throw new BackupException("La conexión '{$connection}' no es PostgreSQL; el backup/restore solo soporta pgsql.");
        }

        $directHost = config('backup.direct_host');
        $directPort = config('backup.direct_port');

        /** @var array<string, mixed> $config */
        return new self(
            $config,
            is_scalar($directHost) && (string) $directHost !== '' ? (string) $directHost : null,
            is_scalar($directPort) && (string) $directPort !== '' ? (string) $directPort : null,
        );
    }

    /**
     * Endpoint efectivo. Prioridad: override del comando > DB_DIRECT_* > host/puerto de la conexión
     * (resolviendo `DB_URL` si está presente). Puro: no descubre nada; si el resultado parece PgBouncer y no
     * es `direct`, el servicio descubre el Postgres real.
     */
    public function endpoint(?string $hostOverride = null, ?string $portOverride = null): PgEndpoint
    {
        $config = $this->config;

        if (is_string($config['url'] ?? null) && $config['url'] !== '') {
            $config = (new ConfigurationUrlParser)->parseConfiguration($config);
        }

        $direct = false;
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? '5432');

        if ($this->directHost !== null) {
            $host = $this->directHost;
            $direct = true;
        }
        if ($this->directPort !== null) {
            $port = $this->directPort;
        }
        if ($hostOverride !== null && $hostOverride !== '') {
            $host = $hostOverride;
            $direct = true;
        }
        if ($portOverride !== null && $portOverride !== '') {
            $port = $portOverride;
            $direct = true;
        }

        $sslmode = $config['sslmode'] ?? null;

        return new PgEndpoint(
            host: $host,
            port: $port,
            database: (string) ($config['database'] ?? ''),
            username: (string) ($config['username'] ?? ''),
            password: (string) ($config['password'] ?? ''),
            sslmode: is_string($sslmode) && $sslmode !== '' ? $sslmode : null,
            direct: $direct,
        );
    }

    /**
     * Forma array del endpoint (compatibilidad con el contrato anterior).
     *
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    public function target(?string $hostOverride = null, ?string $portOverride = null): array
    {
        $e = $this->endpoint($hostOverride, $portOverride);

        return ['host' => $e->host, 'port' => $e->port, 'database' => $e->database, 'username' => $e->username, 'password' => $e->password];
    }

    /**
     * `pg_dump` COMPLETO (estructura + datos, todos los schemas) en formato custom comprimido. Con
     * `$snapshot` usa un snapshot exportado: el dump ve EXACTAMENTE los mismos datos que el inventario del
     * manifest (conteos tomados en esa misma transacción), aunque haya escrituras concurrentes.
     *
     * @return list<string>
     */
    public function dumpCommand(string $file, PgEndpoint $endpoint, ?string $snapshot = null): array
    {
        $command = [
            'pg_dump',
            '--format=custom',   // restaurable con pg_restore (orden de dependencias, selectivo)
            '--compress=9',
            '--no-owner',        // portable entre entornos (no fija dueño)
            '--no-privileges',   // ni GRANTs específicos del entorno origen
        ];

        if ($snapshot !== null) {
            $command[] = '--snapshot='.$snapshot;
        }

        return [
            ...$command,
            '--host='.$endpoint->host,
            '--port='.$endpoint->port,
            '--username='.$endpoint->username,
            '--dbname='.$endpoint->database,
            '--file='.$file,
        ];
    }

    /**
     * `pg_restore` de un dump custom sobre una base NUEVA y vacía (`$endpoint->database`, creada desde
     * template0). Sin `--clean` (no hay nada que limpiar) y con `--exit-on-error`: cualquier error aborta y
     * la base scratch se descarta entera.
     *
     * @return list<string>
     */
    public function restoreCommand(string $file, PgEndpoint $endpoint): array
    {
        return [
            'pg_restore',
            '--no-owner',
            '--no-privileges',
            '--exit-on-error',
            '--host='.$endpoint->host,
            '--port='.$endpoint->port,
            '--username='.$endpoint->username,
            '--dbname='.$endpoint->database,
            $file,
        ];
    }

    /**
     * `pg_restore --list`: tabla de contenidos del dump (sin conectarse a ninguna base).
     *
     * @return list<string>
     */
    public function listCommand(string $file): array
    {
        return ['pg_restore', '--list', $file];
    }

    /** @return list<string> */
    public static function versionCommand(string $binary): array
    {
        return [$binary, '--version'];
    }

    /** Major de la salida de `pg_dump --version` ("pg_dump (PostgreSQL) 18.6" → 18). */
    public static function parseMajorVersion(string $output): ?int
    {
        return preg_match('/\(PostgreSQL\)\s+(\d+)/', $output, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * Ejecuta un comando con `PGPASSWORD` por entorno (nunca en la línea de comandos).
     *
     * @param list<string> $command
     */
    public function run(array $command, PgEndpoint $endpoint): ProcessResult
    {
        $timeout = config('backup.timeout', 7200);

        return Process::env(['PGPASSWORD' => $endpoint->password])
            ->timeout(is_numeric($timeout) ? (int) $timeout : 7200)
            ->run($command);
    }
}
