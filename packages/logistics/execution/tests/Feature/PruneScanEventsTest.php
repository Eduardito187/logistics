<?php

declare(strict_types=1);

use Logistics\Execution\Application\Maintenance\PruneScanEvents;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\ScanEventRecord;

// Motivo del skip cuando la suite no corre contra PostgreSQL (logistics_test).
const PRUNE_SCANS_PG_REASON = 'Requiere PostgreSQL (logistics_test).';

/**
 * C7 — Poda de retención de escaneos en terreno (execution_scan_events). Verifica dry-run, borrado por
 * ventana (occurred_at), chunking e idempotencia. Mismo patrón que la poda de pings GPS.
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    $this->prune = app(PruneScanEvents::class);
});

it('dry-run cuenta los escaneos viejos pero no borra', function (): void {
    /** @var PruneScanEvents $prune */
    $prune = $this->prune;

    ScanEventRecord::factory()->count(4)->create(['occurred_at' => now()->subDays(500)]);
    ScanEventRecord::factory()->count(3)->create(['occurred_at' => now()->subDays(10)]);

    $report = $prune->handle(retentionDays: 365, chunkSize: 1000, dryRun: true);

    expect($report->matched)->toBe(4)
        ->and($report->deleted)->toBe(0)
        ->and(ScanEventRecord::query()->count())->toBe(7);
})->skip(env('DB_CONNECTION') !== 'pgsql', PRUNE_SCANS_PG_REASON);

it('con --force borra solo los escaneos anteriores a la ventana', function (): void {
    /** @var PruneScanEvents $prune */
    $prune = $this->prune;

    ScanEventRecord::factory()->count(5)->create(['occurred_at' => now()->subDays(500)]);
    ScanEventRecord::factory()->count(2)->create(['occurred_at' => now()->subDays(10)]);

    $report = $prune->handle(retentionDays: 365, chunkSize: 2, dryRun: false);

    // 5 viejas en lotes de 2 => 3 lotes; 2 recientes se conservan.
    expect($report->deleted)->toBe(5)
        ->and($report->chunks)->toBe(3)
        ->and(ScanEventRecord::query()->count())->toBe(2);

    // Idempotente: segunda corrida no borra nada.
    $second = $prune->handle(retentionDays: 365, chunkSize: 2, dryRun: false);
    expect($second->deleted)->toBe(0);
})->skip(env('DB_CONNECTION') !== 'pgsql', PRUNE_SCANS_PG_REASON);

it('el comando hace dry-run por defecto', function (): void {
    ScanEventRecord::factory()->count(3)->create(['occurred_at' => now()->subDays(800)]);

    $this->artisan('logistics:execution:prune-scan-events')->assertSuccessful();
    expect(ScanEventRecord::query()->count())->toBe(3);

    $this->artisan('logistics:execution:prune-scan-events', ['--force' => true])->assertSuccessful();
    expect(ScanEventRecord::query()->count())->toBe(0);
})->skip(env('DB_CONNECTION') !== 'pgsql', PRUNE_SCANS_PG_REASON);
