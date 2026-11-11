<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Backup/restore COMPLETO de la base PostgreSQL (esquema + datos) con las herramientas nativas
 * `pg_dump`/`pg_restore`. La construcción de los comandos es PURA (sin I/O), para poder testearla sin
 * ejecutar nada; la ejecución real va por el `Process` de Laravel (fakeable en tests).
 *
 * El dump usa el formato CUSTOM de Postgres (`-Fc`, comprimido): es el respaldo íntegro y restaurable con
 * `pg_restore` (permite `--clean`/`--if-exists`, orden de dependencias, restauración selectiva). Se evita
 * depender de PgBouncer: el host/puerto se pueden sobreescribir para apuntar directo al Postgres real
 * (PgBouncer en modo transacción no soporta algunas operaciones de dump/restore).
 */
final class PostgresBackup
{
    /**
     * @param array<string, mixed> $config configuración de la conexión (config/database.php)
     */
    public function __construct(private readonly array $config) {}

    /**
     * Construye el backup para una conexión del `config/database.php`. Falla claro si no es PostgreSQL:
     * este mecanismo solo respalda pgsql (SQLite/otros tienen su propio flujo).
     */
    public static function forConnection(string $connection): self
    {
        $config = config("database.connections.{$connection}");

        if (!is_array($config) || ($config['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException("La conexión '{$connection}' no es PostgreSQL; el backup/restore solo soporta pgsql.");
        }

        /** @var array<string, mixed> $config */
        return new self($config);
    }

    /**
     * Endpoint efectivo (host/puerto/base/usuario/clave), resolviendo `DB_URL` si está presente y
     * aplicando overrides opcionales de host/puerto (para apuntar al Postgres real y no a PgBouncer).
     *
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    public function target(?string $hostOverride = null, ?string $portOverride = null): array
    {
        $config = $this->config;

        // Si la conexión se define por URL (DB_URL), se expande a host/puerto/base/usuario/clave.
        if (is_string($config['url'] ?? null) && $config['url'] !== '') {
            $config = (new ConfigurationUrlParser)->parseConfiguration($config);
        }

        $host = $hostOverride !== null && $hostOverride !== '' ? $hostOverride : (string) ($config['host'] ?? '127.0.0.1');
        $port = $portOverride !== null && $portOverride !== '' ? $portOverride : (string) ($config['port'] ?? '5432');

        return [
            'host' => $host,
            'port' => $port,
            'database' => (string) ($config['database'] ?? ''),
            'username' => (string) ($config['username'] ?? ''),
            'password' => (string) ($config['password'] ?? ''),
        ];
    }

    /**
     * Comando `pg_dump` para un backup COMPLETO (esquema + datos) en formato custom comprimido.
     *
     * @return list<string>
     */
    public function dumpCommand(string $file, ?string $hostOverride = null, ?string $portOverride = null): array
    {
        $target = $this->target($hostOverride, $portOverride);

        return [
            'pg_dump',
            '--format=custom',   // formato restaurable con pg_restore (respaldo íntegro)
            '--compress=9',      // comprimido al máximo
            '--no-owner',        // portable entre entornos (no fija dueño)
            '--no-privileges',   // ni GRANTs específicos del entorno origen
            '--host='.$target['host'],
            '--port='.$target['port'],
            '--username='.$target['username'],
            '--dbname='.$target['database'],
            '--file='.$file,
        ];
    }

    /**
     * Comando `pg_restore` para importar POR COMPLETO un backup custom, reemplazando el contenido actual
     * (`--clean --if-exists`: dropea y recrea cada objeto). Operación destructiva: el comando que lo usa
     * exige confirmación explícita.
     *
     * @return list<string>
     */
    public function restoreCommand(string $file, ?string $hostOverride = null, ?string $portOverride = null): array
    {
        $target = $this->target($hostOverride, $portOverride);

        return [
            'pg_restore',
            '--clean',           // dropea los objetos antes de recrearlos (import completo)
            '--if-exists',       // sin errores si el objeto aún no existe
            '--no-owner',
            '--no-privileges',
            '--single-transaction', // todo o nada: si falla, no deja la base a medias
            '--host='.$target['host'],
            '--port='.$target['port'],
            '--username='.$target['username'],
            '--dbname='.$target['database'],
            $file,
        ];
    }

    /**
     * Ejecuta un comando de dump/restore inyectando `PGPASSWORD` por entorno (nunca en la línea de
     * comandos, que es visible en `ps`). Timeout alto: un dump/restore grande puede tardar.
     *
     * @param list<string> $command
     */
    public function run(array $command, ?string $hostOverride = null, ?string $portOverride = null): ProcessResult
    {
        $target = $this->target($hostOverride, $portOverride);

        return Process::env(['PGPASSWORD' => $target['password']])
            ->timeout(3600)
            ->run($command);
    }
}
