<?php

declare(strict_types=1);

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Application\Contract\OutboxObserver;
use Logistics\Outbox\Application\RelayOutbox\Outcome;
use Logistics\Outbox\Application\RelayOutbox\ProcessOutboxMessage;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Domain\Event\DomainEvent;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Punto de extensión de observadores del outbox: un observador que lanza NUNCA cambia el desenlace del
 * despacho, los reintentos ni el dead-letter, y no impide que los demás observadores se ejecuten.
 * PHP puro, sin Laravel ni base de datos.
 */

/**
 * Store en memoria para estas pruebas (nombre propio: Pest carga todos los Unit en un mismo proceso).
 */
function observerTestStore(): OutboxStore
{
    return new class implements OutboxStore
    {
        /** @var array<string, OutboxMessage> */
        public array $messages = [];

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

function observerTestMessage(): OutboxMessage
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
            return 'agg-obs-1';
        }

        public function eventName(): string
        {
            return 'execution.shipment_delivered';
        }

        public function occurredAt(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-11-12T10:00:00+00:00');
        }

        public function payload(): array
        {
            return ['shipment_id' => 'S-1'];
        }
    };

    return OutboxMessage::fromDomainEvent($event, 'execution.shipment', new DateTimeImmutable('2026-11-12T10:00:00+00:00'));
}

/**
 * Observador que siempre lanza, y cuenta cuántas veces lo llamaron.
 */
function explodingObserver(): OutboxObserver
{
    return new class implements OutboxObserver
    {
        public int $calls = 0;

        public function observe(OutboxMessage $message): void
        {
            $this->calls++;

            throw new RuntimeException('observer boom');
        }
    };
}

/**
 * Observador que registra los event_name que vio.
 */
function recordingObserver(): OutboxObserver
{
    return new class implements OutboxObserver
    {
        /** @var list<string> */
        public array $seen = [];

        public function observe(OutboxMessage $message): void
        {
            $this->seen[] = $message->eventName();
        }
    };
}

it('un observador que lanza no cambia un despacho exitoso y no frena a los demás observadores', function (): void {
    $store = observerTestStore();
    $message = observerTestMessage();
    $exploding = explodingObserver();
    $recording = recordingObserver();

    $processor = new ProcessOutboxMessage(
        $store,
        new class implements MessageDispatcher
        {
            public function dispatch(OutboxMessage $message): void {}
        },
        observers: [$exploding, $recording],
    );

    $outcome = $processor->process($message, new DateTimeImmutable('2026-11-12T10:01:00+00:00'));

    expect($outcome)->toBe(Outcome::Dispatched)
        ->and($message->status())->toBe(OutboxStatus::Dispatched)
        ->and($exploding->calls)->toBe(1)
        ->and($recording->seen)->toBe(['execution.shipment_delivered']);
});

it('un observador que lanza no cambia el backoff de un despacho fallido', function (): void {
    $store = observerTestStore();
    $message = observerTestMessage();
    $now = new DateTimeImmutable('2026-11-12T10:01:00+00:00');

    $processor = new ProcessOutboxMessage(
        $store,
        new class implements MessageDispatcher
        {
            public function dispatch(OutboxMessage $message): void
            {
                throw new RuntimeException('reactor down');
            }
        },
        observers: [explodingObserver()],
    );

    $outcome = $processor->process($message, $now);

    // Misma política de siempre: Failed, 1 intento, motivo del REACTOR (no del observador), backoff 1 min.
    expect($outcome)->toBe(Outcome::Failed)
        ->and($message->status())->toBe(OutboxStatus::Failed)
        ->and($message->attempts())->toBe(1)
        ->and($message->lastError())->toBe('reactor down')
        ->and($message->availableAt())->toEqual($now->add(new DateInterval('PT1M')));
});

it('un observador que lanza no evita el dead-letter al agotar los intentos', function (): void {
    $store = observerTestStore();
    $message = observerTestMessage();

    $processor = new ProcessOutboxMessage(
        $store,
        new class implements MessageDispatcher
        {
            public function dispatch(OutboxMessage $message): void
            {
                throw new RuntimeException('reactor down');
            }
        },
        maxAttempts: 1,
        observers: [explodingObserver()],
    );

    expect($processor->process($message, new DateTimeImmutable))->toBe(Outcome::DeadLettered)
        ->and($message->status())->toBe(OutboxStatus::DeadLettered);
});

it('no notifica a los observadores si el mensaje ya estaba en estado terminal', function (): void {
    $message = observerTestMessage();
    $message->markDispatched(new DateTimeImmutable);
    $recording = recordingObserver();

    $processor = new ProcessOutboxMessage(
        observerTestStore(),
        new class implements MessageDispatcher
        {
            public function dispatch(OutboxMessage $message): void {}
        },
        observers: [$recording],
    );

    $processor->process($message, new DateTimeImmutable);

    expect($recording->seen)->toBe([]);
});
