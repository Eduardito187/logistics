<?php

declare(strict_types=1);

use Logistics\Telemetry\Application\Maintenance\PruneTelemetryPings;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\PingRecord;

/**
 * Poda del RAW de pings GPS (telemetry_pings). Verifica dry-run, borrado por ventana (recorded_at),
 * chunking e idempotencia.
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    $this->prune = app(PruneTelemetryPings::class);
});

it('dry-run cuenta los pings viejos pero no borra', function (): void {
    /** @var PruneTelemetryPings $prune */
    $prune = $this->prune;

    PingRecord::factory()->count(4)->create(['recorded_at' => now()->subDays(100)]);
    PingRecord::factory()->count(3)->create(['recorded_at' => now()->subDays(5)]);

    $report = $prune->handle(retentionDays: 60, chunkSize: 1000, dryRun: true);

    expect($report->matched)->toBe(4)
        ->and($report->deleted)->toBe(0)
        ->and(PingRecord::query()->count())->toBe(7);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (logistics_test).');

it('con --force borra solo el RAW anterior a la ventana', function (): void {
    /** @var PruneTelemetryPings $prune */
    $prune = $this->prune;

    PingRecord::factory()->count(5)->create(['recorded_at' => now()->subDays(100)]);
    PingRecord::factory()->count(2)->create(['recorded_at' => now()->subDays(5)]);

    $report = $prune->handle(retentionDays: 60, chunkSize: 2, dryRun: false);

    // 5 viejas en lotes de 2 => 3 lotes; 2 recientes se conservan.
    expect($report->deleted)->toBe(5)
        ->and($report->chunks)->toBe(3)
        ->and(PingRecord::query()->count())->toBe(2);

    // Idempotente: segunda corrida no borra nada.
    $second = $prune->handle(retentionDays: 60, chunkSize: 2, dryRun: false);
    expect($second->deleted)->toBe(0);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (logistics_test).');

it('el comando hace dry-run por defecto', function (): void {
    PingRecord::factory()->count(3)->create(['recorded_at' => now()->subDays(200)]);

    $this->artisan('logistics:telemetry:prune-pings')->assertSuccessful();
    expect(PingRecord::query()->count())->toBe(3);

    $this->artisan('logistics:telemetry:prune-pings', ['--force' => true])->assertSuccessful();
    expect(PingRecord::query()->count())->toBe(0);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (logistics_test).');
