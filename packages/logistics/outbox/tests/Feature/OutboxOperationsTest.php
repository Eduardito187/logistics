<?php

declare(strict_types=1);

use Logistics\Outbox\Application\Command\DiscardMessage\DiscardMessageCommand;
use Logistics\Outbox\Application\Command\RetryMessage\RetryMessageCommand;
use Logistics\Outbox\Application\Query\GetOutboxStats\GetOutboxStatsQuery;
use Logistics\Outbox\Application\ReadModel\OutboxStatsView;
use Logistics\Outbox\Domain\Exception\OutboxException;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Verifica las operaciones del panel del outbox (frente O-07) a nivel de caso de uso: las acciones del
 * Resource de Filament NO escriben el record a mano, despachan RetryMessageCommand / DiscardMessageCommand
 * por el CommandBus. Estos tests ejercitan exactamente esos comandos contra PostgreSQL (logistics_test),
 * y el QueryBus/GetOutboxStats que alimenta el contador de navegación (pendientes y dead-letter).
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    $this->commands = app(CommandBus::class);
    $this->queries = app(QueryBus::class);
});

it('reencola un mensaje fallido dejándolo pendiente y disponible de inmediato', function (): void {
    /** @var CommandBus $commands */
    $commands = $this->commands;

    $record = OutboxMessageRecord::factory()->failed()->create();

    $commands->dispatch(new RetryMessageCommand((string) $record->getKey()));

    $record->refresh();

    expect($record->status)->toBe(OutboxStatus::Pending->value)
        ->and($record->attempts)->toBe(0)
        ->and($record->last_error)->toBeNull()
        ->and($record->available_at->lessThanOrEqualTo(now()))->toBeTrue();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('saca un mensaje de dead-letter al reencolarlo', function (): void {
    /** @var CommandBus $commands */
    $commands = $this->commands;

    $record = OutboxMessageRecord::factory()->deadLettered()->create();

    $commands->dispatch(new RetryMessageCommand((string) $record->getKey()));

    $record->refresh();

    expect($record->status)->toBe(OutboxStatus::Pending->value)
        ->and($record->attempts)->toBe(0);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('rechaza reencolar un mensaje ya despachado', function (): void {
    /** @var CommandBus $commands */
    $commands = $this->commands;

    $record = OutboxMessageRecord::factory()->dispatched()->create();

    expect(fn () => $commands->dispatch(new RetryMessageCommand((string) $record->getKey())))
        ->toThrow(OutboxException::class);

    $record->refresh();

    expect($record->status)->toBe(OutboxStatus::Dispatched->value);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('manda a dead-letter un mensaje despachable con el motivo del operador', function (): void {
    /** @var CommandBus $commands */
    $commands = $this->commands;

    $record = OutboxMessageRecord::factory()->failed()->create();
    $attemptsBefore = $record->attempts;

    $commands->dispatch(new DiscardMessageCommand((string) $record->getKey(), 'Evento huérfano: el pedido ya no existe'));

    $record->refresh();

    // discard() NO suma un intento: es decisión del operador, no un fallo del relay.
    expect($record->status)->toBe(OutboxStatus::DeadLettered->value)
        ->and($record->last_error)->toBe('Evento huérfano: el pedido ya no existe')
        ->and($record->attempts)->toBe($attemptsBefore);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('rechaza mandar a dead-letter un mensaje ya despachado', function (): void {
    /** @var CommandBus $commands */
    $commands = $this->commands;

    $record = OutboxMessageRecord::factory()->dispatched()->create();

    expect(fn () => $commands->dispatch(new DiscardMessageCommand((string) $record->getKey(), 'motivo')))
        ->toThrow(OutboxException::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('cuenta los mensajes por estado para el contador de navegación', function (): void {
    /** @var QueryBus $queries */
    $queries = $this->queries;

    OutboxMessageRecord::factory()->count(3)->create();            // pending
    OutboxMessageRecord::factory()->count(2)->failed()->create();  // failed
    OutboxMessageRecord::factory()->count(4)->dispatched()->create();
    OutboxMessageRecord::factory()->count(1)->deadLettered()->create();

    /** @var OutboxStatsView $stats */
    $stats = $queries->ask(new GetOutboxStatsQuery);

    expect($stats->pending)->toBe(3)
        ->and($stats->failed)->toBe(2)
        ->and($stats->dispatched)->toBe(4)
        ->and($stats->deadLettered)->toBe(1)
        ->and($stats->backlog())->toBe(5)     // pendientes + fallidos (cola viva)
        ->and($stats->total())->toBe(10);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);
