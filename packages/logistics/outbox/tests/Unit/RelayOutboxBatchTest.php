<?php

declare(strict_types=1);

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Application\RelayOutbox\RelayOutboxBatch;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shared\Domain\Event\DomainEvent;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Reloj fijo para controlar el tiempo en los tests (sin Laravel).
 */
function fixedClock(DateTimeImmutable $at): Clock
{
    return new class($at) implements Clock
    {
        public function __construct(private readonly DateTimeImmutable $at) {}

        public function now(): DateTimeImmutable
        {
            return $this->at;
        }
    };
}

/**
 * Store en memoria: devuelve lo que se le carga y guarda los cambios por id.
 */
function inMemoryStore(OutboxMessage $message): OutboxStore
{
    return new class($message) implements OutboxStore
    {
        /** @var array<string, OutboxMessage> */
        public array $messages = [];

        public function __construct(OutboxMessage $message)
        {
            $this->messages[$message->id()->toString()] = $message;
        }

        public function append(OutboxMessage $message): void
        {
            $this->messages[$message->id()->toString()] = $message;
        }

        public function pullBatch(int $limit, DateTimeImmutable $now): array
        {
            return array_values(array_slice($this->messages, 0, $limit));
        }

        public function save(OutboxMessage $message): void
        {
            $this->messages[$message->id()->toString()] = $message;
        }
    };
}

function alwaysFailingDispatcher(): MessageDispatcher
{
    return new class implements MessageDispatcher
    {
        public function dispatch(OutboxMessage $message): void
        {
            throw new RuntimeException('SAP unreachable');
        }
    };
}

function okDispatcher(): MessageDispatcher
{
    return new class implements MessageDispatcher
    {
        public function dispatch(OutboxMessage $message): void {}
    };
}

function pendingMessage(DateTimeImmutable $now): OutboxMessage
{
    $event = new readonly class(Uuid::generate()) implements DomainEvent
    {
        public function __construct(private Uuid $id) {}

        public function eventId(): Uuid
        {
            return $this->id;
        }

        public function aggregateId(): string
        {
            return 'agg-1';
        }

        public function eventName(): string
        {
            return 'sales.order_placed';
        }

        public function occurredAt(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-10-13T10:00:00+00:00');
        }

        public function payload(): array
        {
            return ['order' => 'DM-1'];
        }
    };

    return OutboxMessage::fromDomainEvent($event, 'sales.order', $now);
}

it('dispatches a message successfully and marks it dispatched', function (): void {
    $now = new DateTimeImmutable('2026-10-13T12:00:00+00:00');
    $store = inMemoryStore(pendingMessage($now));

    $result = (new RelayOutboxBatch($store, okDispatcher(), fixedClock($now)))->handle(100);

    expect($result->pulled)->toBe(1)
        ->and($result->dispatched)->toBe(1)
        ->and($result->failed)->toBe(0)
        ->and($result->deadLettered)->toBe(0)
        ->and(array_values($store->messages)[0]->status())->toBe(OutboxStatus::Dispatched);
});

it('applies exponential backoff on each failure', function (int $priorAttempts, int $expectedMinutes): void {
    $now = new DateTimeImmutable('2026-10-13T12:00:00+00:00');
    $message = pendingMessage($now);

    // Llevamos el mensaje al número de intentos previos deseado.
    for ($i = 0; $i < $priorAttempts; $i++) {
        $message->markFailed('earlier', $now);
    }

    $store = inMemoryStore($message);
    (new RelayOutboxBatch($store, alwaysFailingDispatcher(), fixedClock($now)))->handle(100);

    $saved = array_values($store->messages)[0];

    expect($saved->status())->toBe(OutboxStatus::Failed)
        ->and($saved->attempts())->toBe($priorAttempts + 1)
        ->and($saved->availableAt())->toEqual($now->add(new DateInterval("PT{$expectedMinutes}M")));
})->with([
    'intento 1 -> 1min' => [0, 1],
    'intento 2 -> 5min' => [1, 5],
    'intento 3 -> 30min' => [2, 30],
    'intento 4 -> 2h' => [3, 120],
    'intento 5 -> 6h' => [4, 360],
]);

it('dead letters a message once the sixth attempt fails', function (): void {
    $now = new DateTimeImmutable('2026-10-13T12:00:00+00:00');
    $message = pendingMessage($now);
    // Cinco fallas previas: el sexto intento debe descartar.
    for ($i = 0; $i < 5; $i++) {
        $message->markFailed('earlier', $now);
    }

    $store = inMemoryStore($message);
    $result = (new RelayOutboxBatch($store, alwaysFailingDispatcher(), fixedClock($now)))->handle(100);

    $saved = array_values($store->messages)[0];

    expect($result->deadLettered)->toBe(1)
        ->and($saved->status())->toBe(OutboxStatus::DeadLettered)
        ->and($saved->attempts())->toBe(6);
});
