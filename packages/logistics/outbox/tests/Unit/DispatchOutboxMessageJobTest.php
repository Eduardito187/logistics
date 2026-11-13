<?php

declare(strict_types=1);

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Application\RelayOutbox\ProcessOutboxMessage;
use Logistics\Outbox\Domain\Contract\OutboxMessageFinder;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Outbox\Infrastructure\Queue\DispatchOutboxMessageJob;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shared\Domain\Event\DomainEvent;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Cobertura del job encolado por mensaje (C-2): su handle() relee el mensaje del finder y delega el
 * despacho real en ProcessOutboxMessage (misma política que el relay sincrónico). PHP puro, sin
 * Laravel: dobles en memoria para finder, store, dispatcher y reloj. Cubre las dos ramas de handle():
 * mensaje presente (se despacha) y mensaje ausente (no hace nada, idempotente).
 */
function jobDomainEvent(): DomainEvent
{
    return new readonly class(Uuid::generate()) implements DomainEvent
    {
        public function __construct(private Uuid $id) {}

        public function eventId(): Uuid
        {
            return $this->id;
        }

        public function aggregateId(): string
        {
            return 'agg-job-1';
        }

        public function eventName(): string
        {
            return 'sap.stock_transfer_posted';
        }

        public function occurredAt(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-10-13T10:00:00+00:00');
        }

        public function payload(): array
        {
            return ['material' => '000000000040012345'];
        }
    };
}

/**
 * Reloj fijo en memoria (nombre propio para no chocar con otros archivos de test).
 */
function jobClockAt(DateTimeImmutable $at): Clock
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
 * Store en memoria que registra cada save.
 */
function jobStore(): OutboxStore
{
    return new class implements OutboxStore
    {
        /** @var array<string, OutboxMessage> */
        public array $saved = [];

        public function append(OutboxMessage $message): void
        {
            $this->saved[$message->id()->toString()] = $message;
        }

        public function pullBatch(int $limit, DateTimeImmutable $now): array
        {
            return [];
        }

        public function save(OutboxMessage $message): void
        {
            $this->saved[$message->id()->toString()] = $message;
        }
    };
}

it('despacha el mensaje releído y lo marca despachado', function (): void {
    $now = new DateTimeImmutable('2026-10-13T12:00:00+00:00');
    $message = OutboxMessage::fromDomainEvent(jobDomainEvent(), 'sap.transfer', $now);

    $dispatcher = new class implements MessageDispatcher
    {
        public int $calls = 0;

        public function dispatch(OutboxMessage $message): void
        {
            $this->calls++;
        }
    };

    $finder = new class($message) implements OutboxMessageFinder
    {
        public function __construct(private readonly OutboxMessage $message) {}

        public function find(OutboxMessageId $id): ?OutboxMessage
        {
            return $this->message;
        }
    };

    $store = jobStore();
    $processor = new ProcessOutboxMessage($store, $dispatcher);

    $job = new DispatchOutboxMessageJob($message->id()->toString(), 'outbox');
    $job->handle($finder, $processor, jobClockAt($now));

    expect($dispatcher->calls)->toBe(1)
        ->and($message->status())->toBe(OutboxStatus::Dispatched);
});

it('no hace nada cuando el mensaje ya no existe (idempotente)', function (): void {
    $now = new DateTimeImmutable('2026-10-13T12:00:00+00:00');

    $dispatcher = new class implements MessageDispatcher
    {
        public int $calls = 0;

        public function dispatch(OutboxMessage $message): void
        {
            $this->calls++;
        }
    };

    $finder = new class implements OutboxMessageFinder
    {
        public function find(OutboxMessageId $id): ?OutboxMessage
        {
            return null;
        }
    };

    $store = jobStore();
    $processor = new ProcessOutboxMessage($store, $dispatcher);

    $job = new DispatchOutboxMessageJob(Uuid::generate()->value, 'outbox');
    $job->handle($finder, $processor, jobClockAt($now));

    expect($dispatcher->calls)->toBe(0)
        ->and($store->saved)->toBe([]);
});
