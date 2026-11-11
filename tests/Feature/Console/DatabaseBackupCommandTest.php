<?php

declare(strict_types=1);

use App\Support\Database\PostgresBackup;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

// Backup/restore COMPLETO de PostgreSQL (db:backup / db:restore). Nada se ejecuta de verdad: el Process de
// Laravel está fakeado, así que verificamos la CONSTRUCCIÓN de los comandos y el control de errores/guards.

beforeEach(function (): void {
    // Conexión pgsql determinista para no depender del entorno.
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

it('construye el comando pg_dump de backup completo (custom, comprimido, con endpoint)', function (): void {
    $cmd = PostgresBackup::forConnection('pg_backup_test')->dumpCommand('/tmp/out.dump');

    expect($cmd[0])->toBe('pg_dump')
        ->and($cmd)->toContain('--format=custom')
        ->and($cmd)->toContain('--compress=9')
        ->and($cmd)->toContain('--no-owner')
        ->and($cmd)->toContain('--host=pg-host')
        ->and($cmd)->toContain('--port=5999')
        ->and($cmd)->toContain('--username=usr')
        ->and($cmd)->toContain('--dbname=mi_base')
        ->and($cmd)->toContain('--file=/tmp/out.dump');
});

it('permite override de host/puerto para saltar PgBouncer', function (): void {
    $cmd = PostgresBackup::forConnection('pg_backup_test')->dumpCommand('/tmp/out.dump', 'postgres-real', '5432');

    expect($cmd)->toContain('--host=postgres-real')
        ->and($cmd)->toContain('--port=5432');
});

it('construye el comando pg_restore de import completo (clean, if-exists, transacción única)', function (): void {
    $cmd = PostgresBackup::forConnection('pg_backup_test')->restoreCommand('/tmp/in.dump');

    expect($cmd[0])->toBe('pg_restore')
        ->and($cmd)->toContain('--clean')
        ->and($cmd)->toContain('--if-exists')
        ->and($cmd)->toContain('--single-transaction')
        ->and($cmd)->toContain('--dbname=mi_base')
        ->and($cmd)->toContain('/tmp/in.dump');
});

it('db:backup ejecuta pg_dump y termina ok', function (): void {
    Process::fake();

    $this->artisan('db:backup', ['--connection' => 'pg_backup_test', '--path' => storage_path('app/backups/test.dump')])
        ->assertSuccessful();

    Process::assertRan(function ($process): bool {
        $cmd = $process->command;

        return is_array($cmd) && $cmd[0] === 'pg_dump' && in_array('--dbname=mi_base', $cmd, true);
    });
});

it('db:backup inyecta PGPASSWORD por entorno, nunca en la línea de comandos', function (): void {
    Process::fake();

    $this->artisan('db:backup', ['--connection' => 'pg_backup_test'])->assertSuccessful();

    Process::assertRan(function ($process): bool {
        // La clave va en el entorno del proceso, no como argumento visible.
        $env = $process->environment;
        $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return ($env['PGPASSWORD'] ?? null) === 's3cret' && !str_contains($cmd, 's3cret');
    });
});

it('db:backup falla con gracia si pg_dump termina en error', function (): void {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'pg_dump: fatal', exitCode: 1)]);

    $this->artisan('db:backup', ['--connection' => 'pg_backup_test'])
        ->assertFailed();
});

it('db:restore exige archivo existente', function (): void {
    Process::fake();

    $this->artisan('db:restore', ['file' => '/no/existe.dump', '--connection' => 'pg_backup_test'])
        ->assertFailed();

    Process::assertNothingRan();
});

it('db:restore con --force ejecuta pg_restore sin preguntar', function (): void {
    Process::fake();
    $file = storage_path('app/backups/'.Str::random(8).'.dump');
    @mkdir(dirname($file), 0775, true);
    file_put_contents($file, 'x');

    $this->artisan('db:restore', ['file' => $file, '--connection' => 'pg_backup_test', '--force' => true])
        ->assertSuccessful();

    Process::assertRan(fn ($process): bool => is_array($process->command) && $process->command[0] === 'pg_restore');

    @unlink($file);
});

it('db:restore cancela sin --force cuando el operador dice que no', function (): void {
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
