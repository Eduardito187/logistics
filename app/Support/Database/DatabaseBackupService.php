<?php

declare(strict_types=1);

namespace App\Support\Database;

use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Orquesta el ciclo completo de backup de la BASE DE DATOS (estructura + datos; nada de archivos,
 * volúmenes ni contenedores):
 *
 * - dump(): snapshot exportado + inventario de estructura + `pg_dump --snapshot` + conteo exacto de filas
 *   en la MISMA transacción (el manifest describe exactamente lo que entró al dump).
 * - restoreIntoScratch(): restaura en una base NUEVA (`TEMPLATE template0`, sin `--clean`) y compara
 *   contra el manifest (o, sin manifest, contra el TOC del dump).
 * - swap(): la base viva pasa a `<db>_prev_<ts>` y la restaurada toma su nombre.
 *
 * Los comandos (backup:*, db:*) son finos: parsean opciones, confirman y delegan acá.
 */
final class DatabaseBackupService
{
    /** @var Closure(string): void */
    private readonly Closure $log;

    /**
     * @param (Closure(string): void)|null $log salida de progreso (los comandos la mandan a consola)
     */
    public function __construct(
        private readonly PostgresBackup $backup,
        private readonly PostgresAdmin $admin,
        ?Closure $log = null,
    ) {
        $this->log = $log ?? static function (string $line): void {};
    }

    /**
     * Arma el servicio para una conexión, resolviendo el endpoint DIRECTO a Postgres (override > DB_DIRECT_*
     * > conexión; si la conexión es PgBouncer, descubre el Postgres real).
     *
     * @param (Closure(string): void)|null $log
     */
    public static function forConnection(string $connection, ?string $host = null, ?string $port = null, ?Closure $log = null): self
    {
        $backup = PostgresBackup::forConnection($connection);
        $endpoint = $backup->endpoint($host, $port);

        if (!$endpoint->direct && $endpoint->looksLikePooler()) {
            $real = PostgresAdmin::discoverBackend($endpoint);
            if ($real === null) {
                throw new RuntimeException("La conexión apunta a un pooler ({$endpoint->host}:{$endpoint->port}) y no se pudo descubrir el Postgres real. Definí DB_DIRECT_HOST/DB_DIRECT_PORT o usá --host/--port.");
            }
            if ($log !== null) {
                $log("Aviso: {$endpoint->host}:{$endpoint->port} parece PgBouncer; uso el Postgres real {$real->host}:{$real->port}. Fijá DB_DIRECT_HOST/DB_DIRECT_PORT para no depender del descubrimiento.");
            }
            $endpoint = $real;
        }

        $maintenance = config('backup.maintenance_database', 'postgres');

        return new self($backup, new PostgresAdmin($endpoint, is_string($maintenance) && $maintenance !== '' ? $maintenance : 'postgres'), $log);
    }

    public function endpoint(): PgEndpoint
    {
        return $this->admin->endpoint();
    }

    public function admin(): PostgresAdmin
    {
        return $this->admin;
    }

    /**
     * Valida que `pg_dump` y `pg_restore` del cliente tengan major >= al del servidor (un cliente más viejo
     * no puede leer/escribir el catálogo nuevo y produce dumps incompletos o falla a mitad).
     *
     * @return array{server: string, server_num: int, pg_dump: string, pg_restore: string}
     */
    public function assertClientVersions(): array
    {
        $server = $this->admin->serverVersion();
        $serverMajor = intdiv($server['num'], 10000);
        $found = [];

        foreach (['pg_dump', 'pg_restore'] as $binary) {
            try {
                $result = Process::timeout(30)->run(PostgresBackup::versionCommand($binary));
            } catch (Throwable $e) {
                throw new RuntimeException("No se pudo ejecutar {$binary}: {$e->getMessage()}. ¿Está instalado postgresql-client?");
            }
            $output = trim($result->output());
            $major = PostgresBackup::parseMajorVersion($output);

            if (!$result->successful() || $major === null) {
                throw new RuntimeException("No se pudo leer la versión de {$binary} (¿está instalado postgresql-client?): ".trim($result->errorOutput().' '.$output));
            }
            if ($major < $serverMajor) {
                throw new RuntimeException("{$binary} {$major} es más viejo que el servidor PostgreSQL {$server['text']} (major {$serverMajor}). Instalá postgresql-client >= {$serverMajor}.");
            }
            $found[$binary] = $output;
        }

        return ['server' => $server['text'], 'server_num' => $server['num'], 'pg_dump' => $found['pg_dump'], 'pg_restore' => $found['pg_restore']];
    }

    /**
     * Dump COMPLETO de la base del endpoint a `$file` (escribe a `$file` directo: el llamador usa un
     * nombre temporal y renombra al final) y devuelve la sección `database` del manifest.
     *
     * @param  array{server: string, server_num: int, pg_dump: string, pg_restore: string} $versions
     * @return array<string, mixed>
     */
    public function dump(string $file, array $versions): array
    {
        $endpoint = $this->endpoint();
        $pdo = $this->admin->connect();

        try {
            $snapshot = $this->admin->beginSnapshot($pdo);
            // Inventario (y valor de secuencias) ANTES del dump; conteos en el mismo snapshot.
            $inventory = $this->admin->inventory($pdo);

            ($this->log)("pg_dump de '{$endpoint->database}' en {$endpoint->host}:{$endpoint->port} (snapshot {$snapshot}) …");
            $result = $this->backup->run($this->backup->dumpCommand($file, $endpoint, $snapshot), $endpoint);
            if (!$result->successful()) {
                throw new RuntimeException('pg_dump terminó con error: '.trim($result->errorOutput() !== '' ? $result->errorOutput() : $result->output()));
            }

            ($this->log)('Contando filas por tabla (count(*) exacto, mismo snapshot) …');
            $rowCounts = $this->admin->rowCounts($pdo);
        } finally {
            try {
                $pdo->exec('COMMIT');
            } catch (Throwable) {
                // La transacción pudo no haberse abierto; nada que cerrar.
            }
        }

        $database = [
            'name' => $endpoint->database,
            'server_version' => $versions['server'],
            'server_version_num' => $versions['server_num'],
            'pg_dump_version' => $versions['pg_dump'],
            'file' => basename($file),
            'size' => (int) filesize($file),
            'sha256' => (string) hash_file('sha256', $file),
            'settings' => $this->admin->databaseSettings($endpoint->database),
            'inventory' => $inventory,
            'row_counts' => $rowCounts,
        ];
        $database['summary'] = BackupManifest::summary($database);

        return $database;
    }

    /**
     * Restaura `$dumpFile` en la base NUEVA `$scratch` (template0) y la compara contra `$expected` (sección
     * `database` del manifest) o, si es null, contra el TOC del dump. NO dropea la scratch: decide el llamador.
     *
     * @param  array<string, mixed>|null                                   $expected
     * @return list<array{item: string, expected: string, actual: string}>
     */
    public function restoreIntoScratch(string $dumpFile, string $scratch, ?array $expected): array
    {
        $target = $this->endpoint()->withDatabase($scratch);

        ($this->log)("Creando base scratch '{$scratch}' (TEMPLATE template0) …");
        $this->admin->createDatabase($scratch);

        ($this->log)("pg_restore en '{$scratch}' …");
        $result = $this->backup->run($this->backup->restoreCommand($dumpFile, $target), $target);
        if (!$result->successful()) {
            throw new RuntimeException('pg_restore terminó con error: '.trim($result->errorOutput() !== '' ? $result->errorOutput() : $result->output()));
        }

        ($this->log)('Comparando estructura y conteo de filas …');
        $pdo = $this->admin->connect($scratch);
        $inventory = $this->admin->inventory($pdo);
        $actual = ['inventory' => $inventory, 'row_counts' => $this->admin->rowCounts($pdo)];
        unset($pdo);

        $diffs = BackupManifest::compareToc($this->tocTables($dumpFile), [...self::listOf($inventory['tables'] ?? []), ...self::listOf($inventory['partitioned_tables'] ?? [])]);

        return $expected !== null ? [...$diffs, ...BackupManifest::compare($expected, $actual)] : $diffs;
    }

    /** Dropea una base scratch sin propagar errores (se usa en `finally`). */
    public function dropScratchQuietly(string $scratch): void
    {
        try {
            $this->admin->dropScratchDatabase($scratch);
        } catch (Throwable $e) {
            ($this->log)("Aviso: no se pudo dropear la base scratch '{$scratch}': {$e->getMessage()}");
        }
    }

    /**
     * Restore SEGURO por swap de `$dumpFile` sobre la base del endpoint. Devuelve el resultado para que el
     * comando imprima rollback/limpieza. Si la verificación previa falla, la base viva NO se toca.
     *
     * @param  array<string, mixed>|null                                                                                                                                                                                   $expected
     * @return array{live: string, prev: string|null, diffs: list<array{item: string, expected: string, actual: string}>, final_diffs: list<array{item: string, expected: string, actual: string}>, verified_counts: bool}
     */
    public function safeRestore(string $dumpFile, ?array $expected, DateTimeImmutable $at): array
    {
        $live = $this->endpoint()->database;
        $scratch = BackupManifest::auxiliaryName($live, 'restore', $at);
        $prev = BackupManifest::auxiliaryName($live, 'prev', $at);
        $liveExisted = $this->admin->databaseOid($live) !== null;

        try {
            $diffs = $this->restoreIntoScratch($dumpFile, $scratch, $expected);
        } catch (Throwable $e) {
            $this->dropScratchQuietly($scratch);

            throw $e;
        }

        if ($diffs !== []) {
            $this->dropScratchQuietly($scratch);

            return ['live' => $live, 'prev' => null, 'diffs' => $diffs, 'final_diffs' => [], 'verified_counts' => $expected !== null];
        }

        $restoredOid = $this->admin->databaseOid($scratch);
        ($this->log)("Swap: '{$live}' → '{$prev}', '{$scratch}' → '{$live}' (terminando conexiones) …");
        try {
            $this->admin->swap($live, $scratch, $prev);
        } catch (Throwable $e) {
            $this->dropScratchQuietly($scratch);

            throw new RuntimeException('Falló el swap; la base viva quedó como estaba: '.$e->getMessage(), 0, $e);
        }

        // Verificación final: el nombre vivo apunta a la base restaurada y su contenido coincide.
        $final = [];
        if ($this->admin->databaseOid($live) !== $restoredOid) {
            $final[] = ['item' => "database {$live}", 'expected' => "oid {$restoredOid}", 'actual' => 'otra base'];
        } elseif ($expected !== null) {
            $pdo = $this->admin->connect($live);
            $final = BackupManifest::compare($expected, ['inventory' => $this->admin->inventory($pdo), 'row_counts' => $this->admin->rowCounts($pdo)]);
        }

        return ['live' => $live, 'prev' => $liveExisted ? $prev : null, 'diffs' => [], 'final_diffs' => $final, 'verified_counts' => $expected !== null];
    }

    /** @return list<string> */
    public function tocTables(string $dumpFile): array
    {
        $result = $this->backup->run($this->backup->listCommand($dumpFile), $this->endpoint());
        if (!$result->successful()) {
            throw new RuntimeException('pg_restore --list no pudo leer el dump (¿archivo corrupto?): '.trim($result->errorOutput()));
        }

        return BackupManifest::tablesFromToc($result->output());
    }

    /** Commit git del código desplegado, si se puede obtener sin romper nada (si no, null). */
    public static function gitCommit(): ?string
    {
        try {
            $result = Process::timeout(5)->run(['git', '-C', base_path(), 'rev-parse', 'HEAD']);
        } catch (Throwable) {
            return null;
        }
        $sha = trim($result->output());

        return $result->successful() && preg_match('/^[0-9a-f]{40}$/', $sha) === 1 ? $sha : null;
    }

    /** @return list<string> */
    private static function listOf(mixed $value): array
    {
        return is_array($value) ? array_values(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $value)) : [];
    }
}
