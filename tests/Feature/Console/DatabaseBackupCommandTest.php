<?php

declare(strict_types=1);

use App\Support\Database\PostgresBackup;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

// Construcción PURA de los comandos de backup/restore y guards de los comandos (sin tocar ninguna base:
// el Process de Laravel está fakeado y los casos cortan antes de cualquier conexión). El ciclo real
// (pg_dump/pg_restore contra Postgres) está en BackupBundleIntegrationTest.

beforeEach(function (): void {
    config()->set('backup.direct_host', null);
    config()->set('backup.direct_port', null);
    config()->set('database.connections.pg_backup_test', [
        'driver' => 'pgsql',
        'host' => 'pg-host',
        'port' => '5999',
        'database' => 'mi_base',
        'username' => 'usr',
        'password' => 's3cret',
    ]);
});

it('rechaza conexiones que no son PostgreSQL', function (): void {
    config()->set('database.connections.no_pg', ['driver' => 'sqlite', 'database' => ':memory:']);

    expect(fn () => PostgresBackup::forConnection('no_pg'))
        ->toThrow(RuntimeException::class);
});

it('construye el comando pg_dump de backup completo (custom, comprimido, snapshot, con endpoint)', function (): void {
    $backup = PostgresBackup::forConnection('pg_backup_test');
    $cmd = $backup->dumpCommand('/tmp/out.dump', $backup->endpoint(), '00000003-0000001B-1');

    expect($cmd[0])->toBe('pg_dump')
        ->and($cmd)->toContain('--format=custom')
        ->and($cmd)->toContain('--compress=9')
        ->and($cmd)->toContain('--no-owner')
        ->and($cmd)->toContain('--snapshot=00000003-0000001B-1')
        ->and($cmd)->toContain('--host=pg-host')
        ->and($cmd)->toContain('--port=5999')
        ->and($cmd)->toContain('--username=usr')
        ->and($cmd)->toContain('--dbname=mi_base')
        ->and($cmd)->toContain('--file=/tmp/out.dump')
        ->and(implode(' ', $cmd))->not->toContain('s3cret');
});

it('va DIRECTO a Postgres por defecto (DB_DIRECT_HOST/PORT) y los overrides del comando ganan', function (): void {
    config()->set('backup.direct_host', 'postgres');
    config()->set('backup.direct_port', '5432');
    $backup = PostgresBackup::forConnection('pg_backup_test');

    $default = $backup->endpoint();
    $override = $backup->endpoint('otro-pg', '6543');

    expect([$default->host, $default->port, $default->direct])->toBe(['postgres', '5432', true])
        ->and([$override->host, $override->port])->toBe(['otro-pg', '6543'])
        ->and($backup->target()['host'])->toBe('postgres');
});

it('sin DB_DIRECT_* marca como no-directo un endpoint PgBouncer (se descubrirá el Postgres real)', function (): void {
    config()->set('database.connections.pg_backup_test.host', 'pgbouncer');
    config()->set('database.connections.pg_backup_test.port', '6432');

    $endpoint = PostgresBackup::forConnection('pg_backup_test')->endpoint();

    expect($endpoint->direct)->toBeFalse()
        ->and($endpoint->looksLikePooler())->toBeTrue();
});

it('el restore va a una base NUEVA: sin --clean, con --exit-on-error', function (): void {
    $backup = PostgresBackup::forConnection('pg_backup_test');
    $cmd = $backup->restoreCommand('/tmp/in.dump', $backup->endpoint()->withDatabase('mi_base_restore_20261111_100000'));

    expect($cmd[0])->toBe('pg_restore')
        ->and($cmd)->not->toContain('--clean')
        ->and($cmd)->not->toContain('--single-transaction')
        ->and($cmd)->toContain('--exit-on-error')
        ->and($cmd)->toContain('--dbname=mi_base_restore_20261111_100000')
        ->and($cmd)->toContain('/tmp/in.dump');
});

it('inyecta PGPASSWORD por entorno, nunca en la línea de comandos', function (): void {
    Process::fake();
    $backup = PostgresBackup::forConnection('pg_backup_test');

    $backup->run($backup->listCommand('/tmp/in.dump'), $backup->endpoint());

    Process::assertRan(function ($process): bool {
        $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return ($process->environment['PGPASSWORD'] ?? null) === 's3cret' && !str_contains($cmd, 's3cret');
    });
});

it('db:backup falla con gracia si no puede conectarse al servidor', function (): void {
    Process::fake();
    config()->set('database.connections.pg_backup_test.host', '127.0.0.1');
    config()->set('database.connections.pg_backup_test.port', '1');

    $this->artisan('db:backup', ['--connection' => 'pg_backup_test', '--path' => storage_path('app/backups/'.Str::random(8).'.dump')])
        ->assertFailed();

    Process::assertNothingRan();
});

it('db:restore exige archivo existente', function (): void {
    Process::fake();

    $this->artisan('db:restore', ['file' => '/no/existe.dump', '--connection' => 'pg_backup_test'])
        ->assertFailed();

    Process::assertNothingRan();
});

it('backup:verify y backup:restore exigen un bundle existente', function (): void {
    Process::fake();

    $this->artisan('backup:verify', ['bundle' => '/no/existe.tar'])->assertFailed();
    $this->artisan('backup:restore', ['bundle' => '/no/existe.tar', '--force' => true])->assertFailed();

    Process::assertNothingRan();
});

it('db:restore cancela sin --force cuando el operador dice que no (sin tocar nada)', function (): void {
    Process::fake();
    $file = storage_path('app/backups/'.Str::random(8).'.dump');
    @mkdir(dirname($file), 0775, true);
    file_put_contents($file, 'x');

    $this->artisan('db:restore', ['file' => $file, '--connection' => 'pg_backup_test'])
        ->expectsConfirmation("Esto REEMPLAZARÁ por completo la base 'mi_base'. ¿Continuar?", 'no')
        ->assertSuccessful();

    Process::assertNothingRan();

    @unlink($file);
});

it('backup:restore en producción sin --force se bloquea antes de tocar nada', function (): void {
    Process::fake();
    app()->detectEnvironment(fn (): string => 'production');
    $file = storage_path('app/backups/'.Str::random(8).'.tar');
    @mkdir(dirname($file), 0775, true);
    file_put_contents($file, 'x');

    try {
        $this->artisan('backup:restore', ['bundle' => $file, '--connection' => 'pg_backup_test'])
            ->assertFailed();
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
        @unlink($file);
    }

    Process::assertNothingRan();
});
