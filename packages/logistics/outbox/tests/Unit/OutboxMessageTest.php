<?php

declare(strict_types=1);

use Logistics\Outbox\Domain\Exception\OutboxException;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Domain\Event\DomainEvent;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Construye un evento de dominio de prueba, en PHP puro (sin Laravel).
 *
 * @param array<string, mixed> $payload
 */
function fakeEvent(?Uuid $eventId = null, string $aggregateId = 'agg-1', array $payload = ['foo' => 'bar']): DomainEvent
{
    return new readonly class($eventId ?? Uuid::generate(), $aggregateId, $payload) implements DomainEvent
    {
        /**
         * @param array<string, mixed> $payload
         */
        public function __construct(
            private Uuid $id,
            private string $aggregate,
            private array $payload,
        ) {}

        public function eventId(): Uuid
        {
            return $this->id;
        }

        public function aggregateId(): string
        {
            return $this->aggregate;
        }

        public function eventName(): string
        {
            return 'network.node_registered';
        }

        public function occurredAt(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-10-13T10:00:00+00:00');
        }

        public function payload(): array
        {
            return $this->payload;
        }
    };
}

it('creates a pending message from a domain event copying its identity and payload', function (): void {
    $eventId = Uuid::generate();
    $now = new DateTimeImmutable('2026-10-13T12:00:00+00:00');

    $message = OutboxMessage::fromDomainEvent(
        fakeEvent($eventId, 'node-42', ['sku' => 'LG-1']),
        'network.node',
        $now,
    );

    expect($message->status())->toBe(OutboxStatus::Pending)
        ->and($message->attempts())->toBe(0)
        ->and($message->eventId()->equals($eventId))->toBeTrue()
        ->and($message->aggregateType())->toBe('network.node')
        ->and($message->aggregateId())->toBe('node-42')
        ->and($message->eventName())->toBe('network.node_registered')
        ->and($message->payload())->toBe(['sku' => 'LG-1'])
        ->and($message->availableAt())->toEqual($now)
        ->and($message->createdAt())->toEqual($now)
        ->and($message->dispatchedAt())->toBeNull()
        ->and($message->lastError())->toBeNull();
});

it('marks a message as dispatched and records the moment', function (): void {
    $now = new DateTimeImmutable('2026-10-13T12:00:00+00:00');
    $message = OutboxMessage::fromDomainEvent(fakeEvent(), 'network.node', $now);

    $dispatchedAt = new DateTimeImmutable('2026-10-13T12:05:00+00:00');
    $message->markDispatched($dispatchedAt);

    expect($message->status())->toBe(OutboxStatus::Dispatched)
        ->and($message->dispatchedAt())->toEqual($dispatchedAt)
        ->and($message->status()->isTerminal())->toBeTrue();
});

it('marks a message as failed, counting the attempt and rescheduling it', function (): void {
    $now = new DateTimeImmutable('2026-10-13T12:00:00+00:00');
    $message = OutboxMessage::fromDomainEvent(fakeEvent(), 'network.node', $now);

    $nextAttempt = new DateTimeImmutable('2026-10-13T12:01:00+00:00');
    $message->markFailed('SAP timeout', $nextAttempt);

    expect($message->status())->toBe(OutboxStatus::Failed)
        ->and($message->attempts())->toBe(1)
        ->and($message->availableAt())->toEqual($nextAttempt)
        ->and($message->lastError())->toBe('SAP timeout')
        ->and($message->status()->isDispatchable())->toBeTrue();
});

it('keeps a failed message dispatchable so it can be retried', function (): void {
    $now = new DateTimeImmutable('2026-10-13T12:00:00+00:00');
    $message = OutboxMessage::fromDomainEvent(fakeEvent(), 'network.node', $now);

    $message->markFailed('first error', $now);
    $message->markFailed('second error', $now);

    expect($message->attempts())->toBe(2)
        ->and($message->lastError())->toBe('second error');
});

it('marks a message as dead lettered after exhausting retries', function (): void {
    $now = new DateTimeImmutable('2026-10-13T12:00:00+00:00');
    $message = OutboxMessage::fromDomainEvent(fakeEvent(), 'network.node', $now);

    $message->markFailed('boom', $now);
    $message->markDeadLettered('final boom');

    expect($message->status())->toBe(OutboxStatus::DeadLettered)
        ->and($message->attempts())->toBe(2)
        ->and($message->lastError())->toBe('final boom')
        ->and($message->status()->isTerminal())->toBeTrue();
});

it('refuses to transition a message that is already in a terminal state', function (callable $secondTransition): void {
    $now = new DateTimeImmutable('2026-10-13T12:00:00+00:00');
    $message = OutboxMessage::fromDomainEvent(fakeEvent(), 'network.node', $now);
    $message->markDispatched($now);

    expect(fn () => $secondTransition($message, $now))->toThrow(OutboxException::class);
})->with([
    're-dispatch' => [fn (OutboxMessage $m, DateTimeImmutable $now) => $m->markDispatched($now)],
    'fail after dispatch' => [fn (OutboxMessage $m, DateTimeImmutable $now) => $m->markFailed('x', $now)],
    'dead letter after dispatch' => [fn (OutboxMessage $m, DateTimeImmutable $now) => $m->markDeadLettered('x')],
]);
