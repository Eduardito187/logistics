<?php

declare(strict_types=1);

use Logistics\Outbox\Application\Maintenance\PruneOutbox;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;

/**
 * Poda del outbox. Verifica que SOLO se podan estados terminales viejos (dispatched / dead_lettered) y
 * que pending/failed NUNCA se tocan, además del dry-run y la ventana de retención por estado.
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    $this->prune = app(PruneOutbox::class);
});

it('dry-run reporta los candidatos por estado pero no borra', function (): void {
    /** @var PruneOutbox $prune */
    $prune = $this->prune;

    OutboxMessageRecord::factory()->count(3)->dispatched()->create(['dispatched_at' => now()->subDays(30)]);
    OutboxMessageRecord::factory()->count(2)->deadLettered()->create(['created_at' => now()->subDays(120)]);

    [$dispatched, $deadLettered] = $prune->handle(
        dispatchedRetentionDays: 14,
        deadLetteredRetentionDays: 90,
        chunkSize: 1000,
        dryRun: true,
    );

    expect($dispatched->matched)->toBe(3)
        ->and($dispatched->deleted)->toBe(0)
        ->and($deadLettered->matched)->toBe(2)
        ->and($deadLettered->deleted)->toBe(0)
        ->and(OutboxMessageRecord::query()->count())->toBe(5);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (logistics_test).');

it('con --force borra los terminales viejos y respeta la retención por estado', function (): void {
    /** @var PruneOutbox $prune */
    $prune = $this->prune;

    // Viejos (se podan) y recientes (se conservan) por cada estado terminal.
    OutboxMessageRecord::factory()->count(2)->dispatched()->create(['dispatched_at' => now()->subDays(30)]);
    OutboxMessageRecord::factory()->count(1)->dispatched()->create(['dispatched_at' => now()->subDays(3)]);
    OutboxMessageRecord::factory()->count(2)->deadLettered()->create(['created_at' => now()->subDays(120)]);
    OutboxMessageRecord::factory()->count(1)->deadLettered()->create(['created_at' => now()->subDays(30)]);

    [$dispatched, $deadLettered] = $prune->handle(
        dispatchedRetentionDays: 14,
        deadLetteredRetentionDays: 90,
        chunkSize: 1000,
        dryRun: false,
    );

    expect($dispatched->deleted)->toBe(2)
        ->and($deadLettered->deleted)->toBe(2)
        ->and(OutboxMessageRecord::query()->where('status', OutboxStatus::Dispatched->value)->count())->toBe(1)
        ->and(OutboxMessageRecord::query()->where('status', OutboxStatus::DeadLettered->value)->count())->toBe(1);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (logistics_test).');

it('NUNCA poda mensajes pending ni failed, por viejos que sean', function (): void {
    /** @var PruneOutbox $prune */
    $prune = $this->prune;

    OutboxMessageRecord::factory()->count(2)->create([
        'status' => OutboxStatus::Pending->value,
        'created_at' => now()->subDays(365),
        'available_at' => now()->subDays(365),
    ]);
    OutboxMessageRecord::factory()->count(2)->failed()->create([
        'created_at' => now()->subDays(365),
        'available_at' => now()->subDays(365),
    ]);

    $prune->handle(
        dispatchedRetentionDays: 1,
        deadLetteredRetentionDays: 1,
        chunkSize: 1000,
        dryRun: false,
    );

    expect(OutboxMessageRecord::query()->where('status', OutboxStatus::Pending->value)->count())->toBe(2)
        ->and(OutboxMessageRecord::query()->where('status', OutboxStatus::Failed->value)->count())->toBe(2);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (logistics_test).');

it('el comando hace dry-run por defecto', function (): void {
    OutboxMessageRecord::factory()->count(3)->dispatched()->create(['dispatched_at' => now()->subDays(60)]);

    $this->artisan('logistics:outbox:prune')->assertSuccessful();
    expect(OutboxMessageRecord::query()->count())->toBe(3);

    $this->artisan('logistics:outbox:prune', ['--force' => true])->assertSuccessful();
    expect(OutboxMessageRecord::query()->count())->toBe(0);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (logistics_test).');
