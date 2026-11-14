<?php

declare(strict_types=1);

use Logistics\Notifications\Application\Command\QueueNotification\QueueNotification;
use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Domain\ValueObject\NotificationId;
use Logistics\Notifications\Infrastructure\Dispatch\CommerceEventNotificationDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Event\DomainEvent;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Mensaje del outbox de un evento de comercio, con nombre, agregado y payload controlables.
 *
 * @param array<string, mixed> $payload
 */
function commerceOutboxMessage(string $eventName, array $payload, string $aggregateId = 'ord-agg'): OutboxMessage
{
    $event = new readonly class($eventName, $payload, $aggregateId) implements DomainEvent
    {
        /**
         * @param array<string, mixed> $payload
         */
        public function __construct(
            private string $name,
            private array $payload,
            private string $aggregateId,
        ) {}

        public function eventId(): Uuid
        {
            return Uuid::generate();
        }

        public function aggregateId(): string
        {
            return $this->aggregateId;
        }

        public function eventName(): string
        {
            return $this->name;
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

    return OutboxMessage::fromDomainEvent($event, 'orders.order', new DateTimeImmutable('2026-10-13T10:00:00+00:00'));
}

/**
 * Repositorio de notificaciones que solo responde a existsForShipmentAndType (idempotencia).
 */
function commerceNotifications(bool $exists): NotificationRepository
{
    return new class($exists) implements NotificationRepository
    {
        public function __construct(private bool $exists) {}

        public function nextIdentity(): NotificationId
        {
            return NotificationId::generate();
        }

        public function save(Notification $notification): void {}

        public function findById(NotificationId $id): ?Notification
        {
            return null;
        }

        public function existsForShipmentAndType(string $shipmentId, MessageType $messageType): bool
        {
            return $this->exists;
        }

        public function findByShipment(string $shipmentId): array
        {
            return [];
        }

        public function latest(int $limit = 100, int $offset = 0): array
        {
            return [];
        }

        public function count(): int
        {
            return 0;
        }
    };
}

/**
 * Bus de comandos que captura lo despachado.
 */
function commerceCapturingBus(): CommandBus
{
    return new class implements CommandBus
    {
        /** @var list<Command> */
        public array $dispatched = [];

        public function dispatch(Command $command): void
        {
            $this->dispatched[] = $command;
        }
    };
}

it('ancla la notificación del checkout al id de la orden (aggregateId) con su tipo', function (): void {
    $bus = commerceCapturingBus();
    $dispatcher = new CommerceEventNotificationDispatcher(commerceNotifications(false), $bus);

    $dispatcher->dispatch(commerceOutboxMessage('orders.order_placed', [
        'customer_email' => 'ana@example.com',
        'customer_phone' => '+59170000000',
        'telegram_chat_id' => '123456',
    ], 'ord-1'));

    expect($bus->dispatched)->toHaveCount(1);

    /** @var QueueNotification $command */
    $command = $bus->dispatched[0];
    expect($command)->toBeInstanceOf(QueueNotification::class)
        ->and($command->shipmentId)->toBe('ord-1')
        ->and($command->messageType)->toBe(MessageType::OrderPlaced->value)
        ->and($command->email)->toBe('ana@example.com')
        ->and($command->phone)->toBe('+59170000000')
        ->and($command->telegramChatId)->toBe('123456');
});

it('prefiere el order_id del payload sobre el aggregateId', function (): void {
    $bus = commerceCapturingBus();
    $dispatcher = new CommerceEventNotificationDispatcher(commerceNotifications(false), $bus);

    $dispatcher->dispatch(commerceOutboxMessage('payments.captured', ['order_id' => 'ord-99'], 'ord-agg'));

    /** @var QueueNotification $command */
    $command = $bus->dispatched[0];
    expect($command->shipmentId)->toBe('ord-99')
        ->and($command->messageType)->toBe(MessageType::PaymentConfirmed->value)
        ->and($command->email)->toBeNull();
});

it('mapea el rechazo del pago al tipo PaymentFailed', function (): void {
    $bus = commerceCapturingBus();
    $dispatcher = new CommerceEventNotificationDispatcher(commerceNotifications(false), $bus);

    $dispatcher->dispatch(commerceOutboxMessage('payments.failed', ['order_id' => 'ord-5']));

    /** @var QueueNotification $command */
    $command = $bus->dispatched[0];
    expect($command->messageType)->toBe(MessageType::PaymentFailed->value);
});

it('ignora los eventos que no están en el mapa', function (): void {
    $bus = commerceCapturingBus();
    $dispatcher = new CommerceEventNotificationDispatcher(commerceNotifications(false), $bus);

    $dispatcher->dispatch(commerceOutboxMessage('orders.order_paid', ['order_id' => 'ord-1']));

    expect($bus->dispatched)->toBe([]);
});

it('es idempotente: no re-encola si ya existe una notificación de esa orden y tipo', function (): void {
    $bus = commerceCapturingBus();
    $dispatcher = new CommerceEventNotificationDispatcher(commerceNotifications(true), $bus);

    $dispatcher->dispatch(commerceOutboxMessage('orders.order_placed', [], 'ord-1'));

    expect($bus->dispatched)->toBe([]);
});

it('ignora contactos del payload que no son cadenas', function (): void {
    $bus = commerceCapturingBus();
    $dispatcher = new CommerceEventNotificationDispatcher(commerceNotifications(false), $bus);

    $dispatcher->dispatch(commerceOutboxMessage('orders.order_placed', [
        'customer_email' => 123,
        'customer_phone' => ['x'],
    ], 'ord-1'));

    /** @var QueueNotification $command */
    $command = $bus->dispatched[0];
    expect($command->email)->toBeNull()
        ->and($command->phone)->toBeNull();
});
