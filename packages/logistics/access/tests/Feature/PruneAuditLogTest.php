<?php

declare(strict_types=1);

use Logistics\Access\Application\Maintenance\PruneAuditLog;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\AuditEntryRecord;

/**
 * Poda de retención de la bitácora de auditoría (access_audit_log). Verifica que el dry-run NO borra y
 * reporta el conteo correcto, que --force borra solo lo anterior a la ventana, y que el barrido es
 * chunked e idempotente.
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    $this->prune = app(PruneAuditLog::class);
});

it('dry-run cuenta las filas viejas pero no borra ninguna', function (): void {
    /** @var PruneAuditLog $prune */
    $prune = $this->prune;

    AuditEntryRecord::factory()->count(3)->create(['occurred_at' => now()->subDays(200)]);
    AuditEntryRecord::factory()->count(2)->create(['occurred_at' => now()->subDays(10)]);

    $report = $prune->handle(retentionDays: 180, chunkSize: 1000, dryRun: true);

    expect($report->dryRun)->toBeTrue()
        ->and($report->matched)->toBe(3)
        ->and($report->deleted)->toBe(0)
        ->and(AuditEntryRecord::query()->count())->toBe(5);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (logistics_test).');

it('con --force borra solo lo anterior a la ventana y respeta la retención', function (): void {
    /** @var PruneAuditLog $prune */
    $prune = $this->prune;

    AuditEntryRecord::factory()->count(4)->create(['occurred_at' => now()->subDays(200)]);
    AuditEntryRecord::factory()->count(3)->create(['occurred_at' => now()->subDays(10)]);

    $report = $prune->handle(retentionDays: 180, chunkSize: 1000, dryRun: false);

    expect($report->dryRun)->toBeFalse()
        ->and($report->deleted)->toBe(4)
        ->and(AuditEntryRecord::query()->count())->toBe(3);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (logistics_test).');

it('borra en lotes (chunk) y es idempotente', function (): void {
    /** @var PruneAuditLog $prune */
    $prune = $this->prune;

    AuditEntryRecord::factory()->count(5)->create(['occurred_at' => now()->subDays(200)]);

    $first = $prune->handle(retentionDays: 180, chunkSize: 2, dryRun: false);

    // 5 filas en lotes de 2 => 3 lotes (2 + 2 + 1).
    expect($first->deleted)->toBe(5)
        ->and($first->chunks)->toBe(3)
        ->and(AuditEntryRecord::query()->count())->toBe(0);

    // Segunda corrida: ya no queda nada que podar (idempotente).
    $second = $prune->handle(retentionDays: 180, chunkSize: 2, dryRun: false);
    expect($second->deleted)->toBe(0)
        ->and($second->chunks)->toBe(0);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (logistics_test).');

it('el comando hace dry-run por defecto (sin --force no borra)', function (): void {
    AuditEntryRecord::factory()->count(2)->create(['occurred_at' => now()->subDays(400)]);

    $this->artisan('logistics:access:prune-audit-log', ['--days' => 180])
        ->assertSuccessful();

    expect(AuditEntryRecord::query()->count())->toBe(2);

    $this->artisan('logistics:access:prune-audit-log', ['--days' => 180, '--force' => true])
        ->assertSuccessful();

    expect(AuditEntryRecord::query()->count())->toBe(0);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (logistics_test).');
