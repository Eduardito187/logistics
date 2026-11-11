<?php

declare(strict_types=1);

namespace App\Support\Database;

use PDO;

/**
 * I/O contra el servidor PostgreSQL para backups: inventario de estructura, conteo exacto de filas,
 * snapshot exportado y DDL de bases (CREATE/DROP/RENAME DATABASE). Usa PDO directo (no el pool de Laravel)
 * porque trabaja con bases que no son la de la app (scratch, mantenimiento) y necesita estado de sesión.
 *
 * El DDL de bases se hace SIEMPRE conectado a la base de mantenimiento (`postgres`): no se puede renombrar
 * ni dropear la base a la que uno está conectado.
 *
 * Criterio del inventario (idéntico en create, verify y restore):
 * - schemas de usuario = todos menos pg_catalog, information_schema, pg_toast*, pg_temp*;
 * - se excluyen los objetos MIEMBROS de una extensión (los recrea `CREATE EXTENSION`); las tablas de
 *   configuración de extensión (`extconfig`, p. ej. `spatial_ref_sys`) sí se cuentan en row_counts porque
 *   pg_dump respalda sus filas de usuario;
 * - row_counts cuenta las tablas HOJA (relkind 'r', incluidas las particiones) y NO el padre particionado
 *   (relkind 'p'), para no duplicar filas;
 * - constraints NOT NULL ('n', catalogados desde PG18) se excluyen para que el inventario sea comparable
 *   entre majors.
 */
final class PostgresAdmin
{
    private const USER_SCHEMA = "n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg\\_toast%' AND n.nspname NOT LIKE 'pg\\_temp%'";

    public function __construct(
        private readonly PgEndpoint $endpoint,
        private readonly string $maintenanceDatabase = 'postgres',
    ) {}

    public function endpoint(): PgEndpoint
    {
        return $this->endpoint;
    }

    /** Conexión PDO a una base del servidor (por defecto, la del endpoint). */
    public function connect(?string $database = null): PDO
    {
        return self::open($this->endpoint, $database);
    }

    /**
     * Si el endpoint parece PgBouncer y no viene de una fuente directa, pregunta al servidor real su
     * dirección (`inet_server_addr()` responde el Postgres de atrás del pooler) y devuelve el endpoint
     * directo. Null si no se pudo descubrir (socket unix, etc.).
     */
    public static function discoverBackend(PgEndpoint $pooler): ?PgEndpoint
    {
        $stmt = self::open($pooler)->query("SELECT host(inet_server_addr()), current_setting('port')");
        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_NUM) : null;

        if (!is_array($row) || !is_string($row[0] ?? null) || $row[0] === '') {
            return null;
        }

        return $pooler->withServer($row[0], is_scalar($row[1] ?? null) ? (string) $row[1] : '5432');
    }

    /** @return array{num: int, text: string} */
    public function serverVersion(): array
    {
        $pdo = $this->connect($this->maintenanceDatabase);

        return [
            'num' => (int) $this->scalar($pdo, 'SHOW server_version_num'),
            'text' => (string) $this->scalar($pdo, 'SHOW server_version'),
        ];
    }

    /**
     * Abre una transacción REPEATABLE READ de solo lectura y exporta su snapshot: pg_dump (`--snapshot`) y
     * los conteos que se hagan después en ESTA conexión ven exactamente los mismos datos.
     */
    public function beginSnapshot(PDO $pdo): string
    {
        $pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');

        return (string) $this->scalar($pdo, 'SELECT pg_export_snapshot()');
    }

    /**
     * Inventario de estructura (ver forma en BackupManifest). Las secuencias van primero: así, en el
     * backup, su valor se lee ANTES del dump (pg_dump lo verá igual o mayor).
     *
     * @return array<string, mixed>
     */
    public function inventory(PDO $pdo): array
    {
        $notMember = static fn (string $catalog, string $oid): string => "NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = '{$catalog}'::regclass AND d.objid = {$oid} AND d.deptype = 'e')";
        // Cola común de cada consulta: solo schemas de usuario, sin objetos que pertenecen a una extensión, ordenado.
        $userObjects = static fn (string $catalog, string $oid): string => self::USER_SCHEMA.' AND '.$notMember($catalog, $oid).' ORDER BY 1';

        $sequences = [];
        foreach ($this->rows($pdo, "SELECT s.schemaname || '.' || s.sequencename, s.last_value
            FROM pg_sequences s
            JOIN pg_namespace n ON n.nspname = s.schemaname
            JOIN pg_class c ON c.relnamespace = n.oid AND c.relname = s.sequencename
            WHERE ".$userObjects('pg_class', 'c.oid')) as $row) {
            $sequences[(string) $row[0]] = is_numeric($row[1]) ? (int) $row[1] : null;
        }

        $relations = fn (string $relkinds): array => $this->column($pdo, "SELECT n.nspname || '.' || c.relname
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relkind IN ({$relkinds}) AND ".$userObjects('pg_class', 'c.oid'));

        $extensions = [];
        foreach ($this->rows($pdo, 'SELECT extname, extversion FROM pg_extension ORDER BY 1') as $row) {
            $extensions[(string) $row[0]] = (string) $row[1];
        }

        return [
            'schemas' => $this->column($pdo, 'SELECT n.nspname FROM pg_namespace n WHERE '.$userObjects('pg_namespace', 'n.oid')),
            'tables' => $relations("'r'"),
            'partitioned_tables' => $relations("'p'"),
            'indexes' => $this->column($pdo, "SELECT n.nspname || '.' || c.relname
                FROM pg_index i
                JOIN pg_class c ON c.oid = i.indexrelid
                JOIN pg_class t ON t.oid = i.indrelid
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE ".$userObjects('pg_class', 't.oid')),
            'constraints' => $this->column($pdo, "SELECT n.nspname || '.' || COALESCE(t.relname, '-') || '.' || con.conname
                FROM pg_constraint con
                JOIN pg_namespace n ON n.oid = con.connamespace
                LEFT JOIN pg_class t ON t.oid = con.conrelid
                WHERE con.contype <> 'n' AND ".self::USER_SCHEMA.'
                  AND (con.conrelid = 0 OR '.$notMember('pg_class', 't.oid').')
                  AND (con.contypid = 0 OR '.$notMember('pg_type', 'con.contypid').') ORDER BY 1'),
            'views' => $relations("'v'"),
            'materialized_views' => $relations("'m'"),
            'functions' => $this->column($pdo, "SELECT n.nspname || '.' || p.proname || '(' || pg_get_function_identity_arguments(p.oid) || ')'
                FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                WHERE ".$userObjects('pg_proc', 'p.oid')),
            'triggers' => $this->column($pdo, "SELECT n.nspname || '.' || t.relname || '.' || tg.tgname
                FROM pg_trigger tg
                JOIN pg_class t ON t.oid = tg.tgrelid
                JOIN pg_namespace n ON n.oid = t.relnamespace
                WHERE NOT tg.tgisinternal AND ".$userObjects('pg_class', 't.oid')),
            'sequences' => $sequences,
            'extensions' => $extensions,
        ];
    }

    /**
     * Conteo EXACTO (`count(*)`) de cada tabla hoja de los schemas de usuario.
     *
     * @return array<string, int>
     */
    public function rowCounts(PDO $pdo): array
    {
        $tables = $this->rows($pdo, "SELECT n.nspname, c.relname
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relkind = 'r' AND ".self::USER_SCHEMA."
              AND (NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid AND d.deptype = 'e')
                   OR EXISTS (SELECT 1 FROM pg_extension e WHERE c.oid = ANY (e.extconfig)))
            ORDER BY 1, 2");

        $counts = [];
        foreach ($tables as $row) {
            $schema = (string) $row[0];
            $table = (string) $row[1];
            $counts[$schema.'.'.$table] = (int) $this->scalar($pdo, 'SELECT count(*) FROM '.BackupManifest::quoteIdent($schema).'.'.BackupManifest::quoteIdent($table));
        }

        return $counts;
    }

    /**
     * Ajustes a nivel base (`ALTER DATABASE ... SET`): pg_dump sin `--create` no los incluye; se registran
     * en el manifest para reaplicarlos a mano si hiciera falta.
     *
     * @return list<string>
     */
    public function databaseSettings(string $database): array
    {
        $stmt = $this->connect($this->maintenanceDatabase)->prepare('SELECT unnest(s.setconfig)
            FROM pg_db_role_setting s JOIN pg_database d ON d.oid = s.setdatabase
            WHERE d.datname = ? AND s.setrole = 0');
        $stmt->execute([$database]);

        return array_values(array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function databaseOid(string $database): ?int
    {
        $stmt = $this->connect($this->maintenanceDatabase)->prepare('SELECT oid FROM pg_database WHERE datname = ?');
        $stmt->execute([$database]);
        $oid = $stmt->fetchColumn();

        return is_numeric($oid) ? (int) $oid : null;
    }

    /** Crea una base VACÍA desde template0 (sin las extensiones que la imagen de PostGIS mete en template1). */
    public function createDatabase(string $database): void
    {
        $this->connect($this->maintenanceDatabase)->exec('CREATE DATABASE '.BackupManifest::quoteIdent($database).' TEMPLATE template0');
    }

    /**
     * Dropea una base SCRATCH (verify/restore). Guard duro: se niega a dropear cualquier nombre que no tenga
     * el sufijo de scratch, para que un bug jamás pueda borrar la base viva ni la `_prev_`.
     */
    public function dropScratchDatabase(string $database): void
    {
        if (!BackupManifest::isScratchName($database)) {
            throw new BackupException("Me niego a dropear '{$database}': no es una base scratch de backup.");
        }

        $this->connect($this->maintenanceDatabase)->exec('DROP DATABASE IF EXISTS '.BackupManifest::quoteIdent($database).' WITH (FORCE)');
    }

    /**
     * SWAP atómico-en-la-práctica: la base viva `$live` pasa a llamarse `$prev` (con ALLOW_CONNECTIONS
     * false, para que nada la siga usando por error) y `$restored` pasa a llamarse `$live`. Si la viva no
     * existe, solo renombra la restaurada. Si el segundo rename falla, deshace el primero.
     */
    public function swap(string $live, string $restored, string $prev): void
    {
        $pdo = $this->connect($this->maintenanceDatabase);
        $q = BackupManifest::quoteIdent(...);
        $liveExists = $this->databaseOid($live) !== null;

        if ($liveExists) {
            $pdo->exec("ALTER DATABASE {$q($live)} WITH ALLOW_CONNECTIONS false");
        }

        try {
            $this->retryWhileBusy($pdo, [$live, $restored], function () use ($pdo, $q, $live, $restored, $prev, $liveExists): void {
                if ($liveExists && $this->databaseOid($prev) === null) {
                    $pdo->exec("ALTER DATABASE {$q($live)} RENAME TO {$q($prev)}");
                }
                $pdo->exec("ALTER DATABASE {$q($restored)} RENAME TO {$q($live)}");
            });
        } catch (\Throwable $e) {
            // Deshacer: si la viva ya se había renombrado y la nueva no ocupó su lugar, volverla atrás.
            if ($liveExists && $this->databaseOid($live) === null && $this->databaseOid($prev) !== null) {
                $pdo->exec("ALTER DATABASE {$q($prev)} RENAME TO {$q($live)}");
            }
            if ($liveExists && $this->databaseOid($live) !== null) {
                $pdo->exec("ALTER DATABASE {$q($live)} WITH ALLOW_CONNECTIONS true");
            }

            throw $e;
        }
    }

    /**
     * Termina las sesiones de las bases dadas y ejecuta `$ddl`; reintenta si Postgres responde que la base
     * sigue en uso (una sesión colada entre el terminate y el rename).
     *
     * @param list<string> $databases
     */
    private function retryWhileBusy(PDO $pdo, array $databases, callable $ddl): void
    {
        $terminate = $pdo->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ANY (?::text[]) AND pid <> pg_backend_pid()');
        $literal = '{'.implode(',', array_map(static fn (string $d): string => '"'.addcslashes($d, '"\\').'"', $databases)).'}';

        for ($attempt = 1; ; $attempt++) {
            $terminate->execute([$literal]);
            usleep(200_000);

            try {
                $ddl();

                return;
            } catch (\PDOException $e) {
                // 55006 = object_in_use ("is being accessed by other users").
                if ($e->getCode() !== '55006' || $attempt >= 10) {
                    throw $e;
                }
            }
        }
    }

    private static function open(PgEndpoint $endpoint, ?string $database = null): PDO
    {
        return new PDO($endpoint->dsn($database), $endpoint->username, $endpoint->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // Consultas simples sin prepared statements de servidor (compatibles también con PgBouncer).
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);
    }

    private function scalar(PDO $pdo, string $sql): mixed
    {
        $stmt = $pdo->query($sql);

        return $stmt !== false ? $stmt->fetchColumn() : null;
    }

    /** @return list<array<int, mixed>> */
    private function rows(PDO $pdo, string $sql): array
    {
        $stmt = $pdo->query($sql);

        return $stmt !== false ? array_values(array_filter($stmt->fetchAll(PDO::FETCH_NUM), is_array(...))) : [];
    }

    /** @return list<string> */
    private function column(PDO $pdo, string $sql): array
    {
        return array_values(array_map(static fn (array $row): string => is_scalar($row[0] ?? null) ? (string) $row[0] : '', $this->rows($pdo, $sql)));
    }
}
