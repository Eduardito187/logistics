<?php

declare(strict_types=1);

use App\Support\Database\BackupBundle;
use App\Support\Database\DatabaseBackupService;
use App\Support\Database\PostgresAdmin;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

// Ciclo REAL backup:create → backup:verify → backup:restore (y db:backup → db:restore) contra el Postgres de
// la suite, sobre una base PROPIA y descartable `lg_bkit_<random>` (nunca logistics ni logistics_test).
// Se salta si no hay pg_dump/pg_restore o si su major es menor al del servidor (p. ej. CI con cliente viejo).

/** Endpoint directo al Postgres de la suite (descubre el real si la conexión es PgBouncer) o null si no aplica. */
function bkitAdmin(): ?PostgresAdmin
{
    try {
        $service = DatabaseBackupService::forConnection((string) config('database.default'));
        $service->assertClientVersions();

        return new PostgresAdmin($service->endpoint());
    } catch (Throwable) {
        return null;
    }
}

function bkitDropAll(PostgresAdmin $admin, string $database): void
{
    // Solo bases de este test (prefijo lg_bkit_): la fija, su _prev_ y cualquier scratch que haya quedado.
    $pdo = $admin->connect('postgres');
    $names = $pdo->query("SELECT datname FROM pg_database WHERE datname LIKE 'lg\\_bkit\\_%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($names as $name) {
        if (is_string($name) && str_starts_with($name, $database)) {
            $pdo->exec('DROP DATABASE IF EXISTS "'.$name.'" WITH (FORCE)');
        }
    }
}

function bkitScalar(PostgresAdmin $admin, string $database, string $sql): mixed
{
    return $admin->connect($database)->query($sql)->fetchColumn();
}

it('respalda, verifica, detecta un manifest alterado y restaura por swap una base completa', function (): void {
    $admin = bkitAdmin();
    if ($admin === null) {
        $this->markTestSkipped('pg_dump/pg_restore no disponibles o más viejos que el servidor.');
    }

    $database = 'lg_bkit_'.strtolower(Str::random(8));
    $endpoint = $admin->endpoint();
    config()->set('database.connections.pg_bkit', [
        'driver' => 'pgsql', 'host' => $endpoint->host, 'port' => $endpoint->port, 'database' => $database,
        'username' => $endpoint->username, 'password' => $endpoint->password,
    ]);
    $bundle = storage_path('app/backups/test-'.$database.'.tar');
    $dump = storage_path('app/backups/test-'.$database.'.dump');

    try {
        $admin->connect('postgres')->exec('CREATE DATABASE "'.$database.'" TEMPLATE template0');
        $admin->connect($database)->exec(<<<'SQL'
            CREATE TABLE users (id bigserial PRIMARY KEY, name text NOT NULL UNIQUE);
            CREATE TABLE events (id bigint, at date NOT NULL, payload jsonb) PARTITION BY RANGE (at);
            CREATE TABLE events_2026 PARTITION OF events FOR VALUES FROM ('2026-01-01') TO ('2027-01-01');
            CREATE TABLE events_2027 PARTITION OF events FOR VALUES FROM ('2027-01-01') TO ('2028-01-01');
            CREATE INDEX events_at_idx ON events (at);
            CREATE VIEW v_users AS SELECT id, name FROM users;
            CREATE MATERIALIZED VIEW mv_users AS SELECT count(*) AS c FROM users;
            CREATE FUNCTION touch() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN NEW.name := trim(NEW.name); RETURN NEW; END $$;
            CREATE TRIGGER trg_touch BEFORE INSERT ON users FOR EACH ROW EXECUTE FUNCTION touch();
            CREATE SCHEMA audit;
            CREATE TABLE audit.log (id serial PRIMARY KEY, msg text);
            INSERT INTO users (name) VALUES (' ana '), ('beto'), ('caro');
            INSERT INTO events VALUES (1, '2026-02-01', '{}'), (2, '2026-03-01', '{}'), (3, '2026-04-01', '{}'),
                                      (4, '2027-02-01', '{}'), (5, '2027-03-01', '{}');
            INSERT INTO audit.log (msg) VALUES ('a'), ('b');
            SQL);

        // 1) backup:create — manifest con conteo exacto (hojas, sin duplicar el padre particionado) e inventario.
        $this->artisan('backup:create', ['--connection' => 'pg_bkit', '--path' => $bundle])->assertSuccessful();
        expect(is_file($bundle))->toBeTrue()->and(is_file($bundle.'.tmp'))->toBeFalse();

        $work = BackupBundle::makeWorkDir($bundle);
        $manifest = BackupBundle::unpack($bundle, $work);
        $db = $manifest['database'];
        expect($manifest['format'])->toBe(1)
            ->and($db['row_counts'])->toBe([
                'audit.log' => 2, 'public.events_2026' => 3, 'public.events_2027' => 2, 'public.users' => 3,
            ])
            ->and($db['inventory']['schemas'])->toBe(['audit', 'public'])
            ->and($db['inventory']['partitioned_tables'])->toBe(['public.events'])
            ->and($db['inventory']['views'])->toBe(['public.v_users'])
            ->and($db['inventory']['materialized_views'])->toBe(['public.mv_users'])
            ->and($db['inventory']['functions'])->toBe(['public.touch()'])
            ->and($db['inventory']['triggers'])->toBe(['public.users.trg_touch'])
            ->and($db['inventory']['sequences'])->toBe(['audit.log_id_seq' => 2, 'public.users_id_seq' => 3])
            ->and($db['inventory']['indexes'])->toContain('public.events_at_idx', 'public.users_pkey', 'public.users_name_key');

        // 2) backup:verify OK (no destructivo: la scratch no queda).
        $this->artisan('backup:verify', ['bundle' => $bundle, '--connection' => 'pg_bkit'])->assertSuccessful();
        expect(bkitScalar($admin, 'postgres', "SELECT count(*) FROM pg_database WHERE datname LIKE '{$database}\\_verify\\_%'"))->toBe(0);

        // 3) Caso NEGATIVO: manifest alterado → verify falla.
        $tampered = storage_path('app/backups/test-'.$database.'-bad.tar');
        $manifest['database']['row_counts']['public.users'] = 999;
        BackupBundle::writeManifest($work.'/manifest.json', $manifest);
        BackupBundle::pack($tampered, $work);
        $this->artisan('backup:verify', ['bundle' => $tampered, '--connection' => 'pg_bkit'])->assertFailed();
        // …y backup:restore aborta sin tocar la base viva.
        $admin->connect($database)->exec("INSERT INTO users (name) VALUES ('intruso')");
        $this->artisan('backup:restore', ['bundle' => $tampered, '--connection' => 'pg_bkit', '--force' => true])->assertFailed();
        expect(bkitScalar($admin, $database, 'SELECT count(*) FROM users'))->toBe(4);
        File::deleteDirectory($work);
        @unlink($tampered);

        // 4) backup:restore por swap: vuelve al estado del backup; la anterior queda como _prev_ sin conexiones.
        $admin->connect($database)->exec('DROP VIEW v_users');
        $this->artisan('backup:restore', ['bundle' => $bundle, '--connection' => 'pg_bkit', '--force' => true])->assertSuccessful();
        expect(bkitScalar($admin, $database, 'SELECT count(*) FROM users'))->toBe(3)
            ->and(bkitScalar($admin, $database, 'SELECT count(*) FROM v_users'))->toBe(3)
            ->and(bkitScalar($admin, $database, "SELECT last_value FROM pg_sequences WHERE sequencename = 'users_id_seq'"))->toBe(3)
            ->and(bkitScalar($admin, 'postgres', "SELECT count(*) FROM pg_database WHERE datname LIKE '{$database}\\_prev\\_%' AND NOT datallowconn"))->toBe(1);

        // 5) Compatibilidad: db:backup (.dump + .manifest.json) → db:restore por el mismo camino seguro.
        $this->artisan('db:backup', ['--connection' => 'pg_bkit', '--path' => $dump])->assertSuccessful();
        expect(is_file($dump.'.manifest.json'))->toBeTrue();
        $admin->connect($database)->exec('DELETE FROM audit.log');
        sleep(1); // otro segundo: otro nombre _prev_
        $this->artisan('db:restore', ['file' => $dump, '--connection' => 'pg_bkit', '--force' => true])->assertSuccessful();
        expect(bkitScalar($admin, $database, 'SELECT count(*) FROM audit.log'))->toBe(2);
    } finally {
        bkitDropAll($admin, $database);
        @unlink($bundle);
        @unlink($dump);
        @unlink($dump.'.manifest.json');
    }
});
