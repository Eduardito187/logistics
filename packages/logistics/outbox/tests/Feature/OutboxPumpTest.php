<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Logistics\Outbox\Application\RelayOutbox\Outcome;
use Logistics\Outbox\Application\RelayOutbox\ProcessOutboxMessage;
use Logistics\Outbox\Domain\Contract\OutboxMessageFinder;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;
use Logistics\Outbox\Infrastructure\Queue\DispatchOutboxMessageJob;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * C-2 del rediseño de throughput: el dispatcher (`logistics:outbox:pump`) toma un lote y encola UN job
 * por mensaje a la cola de su destino (los avisos al cliente a `notifications`, el resto a `outbox`), de
 * modo que los workers always-on despachen en PARALELO. Verifica el encolado, el ruteo por cola y la
 * idempotencia del procesamiento por mensaje. Corre contra PostgreSQL (logistics_test).
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        $this->markTestSkipped('Requiere PostgreSQL (logistics_test).');
    }
});

it('encola un job por mensaje despachable a la cola de su destino', function (): void {
    Queue::fake();

    // Aviso al cliente → cola `notifications`.
    OutboxMessageRecord::factory()->create([
        'event_name' => 'shipments.shipment_received',
        'status' => OutboxStatus::Pending->value,
        'available_at' => now()->subMinute(),
    ]);
    // Traspaso a SAP → cola `outbox` (por defecto).
    OutboxMessageRecord::factory()->create([
        'event_name' => 'sap.stock_transfer_posted',
        'status' => OutboxStatus::Pending->value,
        'available_at' => now()->subMinute(),
    ]);

    $this->artisan('logistics:outbox:pump', ['--limit' => 100])->assertExitCode(0);

    // Un job por mensaje.
    Queue::assertPushed(DispatchOutboxMessageJob::class, 2);
    // Ruteo por cola: el aviso al cliente va a `notifications`, el traspaso a `outbox`.
    Queue::assertPushedOn('notifications', DispatchOutboxMessageJob::class);
    Queue::assertPushedOn('outbox', DispatchOutboxMessageJob::class);
});

it('no encola mensajes terminales ni los que aún no están disponibles', function (): void {
    Queue::fake();

    OutboxMessageRecord::factory()->dispatched()->create();
    OutboxMessageRecord::factory()->create([
        'status' => OutboxStatus::Pending->value,
        'available_at' => now()->addHour(), // backoff en el futuro
    ]);

    $this->artisan('logistics:outbox:pump', ['--limit' => 100])->assertExitCode(0);

    Queue::assertNothingPushed();
});

it('rechaza un límite de pump menor que uno', function (): void {
    $this->artisan('logistics:outbox:pump', ['--limit' => 0])->assertExitCode(1);
});

it('procesa un mensaje por su id y es idempotente ante reprocesos', function (): void {
    // Evento sin adapter real → cae al NullMessageDispatcher (no sale nada al exterior) y se despacha ok.
    $record = OutboxMessageRecord::factory()->create([
        'event_name' => 'network.node_registered',
        'status' => OutboxStatus::Pending->value,
        'available_at' => now()->subMinute(),
    ]);

    /** @var OutboxMessageFinder $finder */
    $finder = app(OutboxMessageFinder::class);
    /** @var ProcessOutboxMessage $processor */
    $processor = app(ProcessOutboxMessage::class);
    /** @var Clock $clock */
    $clock = app(Clock::class);

    $id = OutboxMessageId::fromString($record->id);

    $message = $finder->find($id);
    expect($message)->not->toBeNull();
    assert($message !== null);

    $first = $processor->process($message, $clock->now());
    $afterFirst = OutboxMessageRecord::query()->find($record->id);
    expect($first)->toBe(Outcome::Dispatched)
        ->and($afterFirst)->not->toBeNull();
    assert($afterFirst instanceof OutboxMessageRecord);
    expect($afterFirst->status)->toBe(OutboxStatus::Dispatched->value);

    // Reproceso (p. ej. el job se reencoló al vencer el lease): el estado ya es terminal → no-op.
    $reloaded = $finder->find($id);
    assert($reloaded !== null);
    $second = $processor->process($reloaded, $clock->now());
    expect($second)->toBe(Outcome::Dispatched);
});
