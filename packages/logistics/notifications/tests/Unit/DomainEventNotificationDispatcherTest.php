<?php

declare(strict_types=1);

use Logistics\Notifications\Application\Command\QueueNotification\QueueNotification;
use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Domain\ValueObject\NotificationId;
use Logistics\Notifications\Infrastructure\Dispatch\DomainEventNotificationDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Event\DomainEvent;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Evento de dominio de prueba, en PHP puro: solo transporta nombre, agregado y payload.
 *
 * @param array<string, mixed> $payload
 */
function domainEvent(string $eventName, array $payload): DomainEvent
{
    return new readonly class($eventName, $payload) implements DomainEvent
    {
        /**
         * @param array<string, mixed> $payload
         */
        public function __construct(
            private string $name,
            private array $payload,
        ) {}

        public function eventId(): Uuid
        {
            return Uuid::generate();
        }

        public function aggregateId(): string
        {
            return 'shp-agg';
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
}

/**
 * Envuelve un evento de dominio en un mensaje del outbox, como lo recibe el dispatcher.
 *
 * @param array<string, mixed> $payload
 */
function outboxMessage(string $eventName, array $payload): OutboxMessage
{
    return OutboxMessage::fromDomainEvent(
        domainEvent($eventName, $payload),
        'shipments.shipment',
        new DateTimeImmutable('2026-10-13T10:00:00+00:00'),
    );
}

/**
 * Repositorio de notificaciones que solo responde a existsForShipmentAndType (la comprobación de
 * idempotencia del dispatcher).
 */
function notificationsExisting(bool $exists): NotificationRepository
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

        public function latest(int $limit = 100): array
        {
            return [];
        }
    };
}

/**
 * Bus de comandos que captura lo despachado.
 */
function capturingBus(): CommandBus
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

it('maps a shipment event to a QueueNotification with the right type and contacts', function (): void {
    $bus = capturingBus();
    $dispatcher = new DomainEventNotificationDispatcher(notificationsExisting(false), $bus);

    $dispatcher->dispatch(outboxMessage('shipments.shipment_received', [
        'shipment_id' => 'shp-1',
        'customer_email' => 'ana@example.com',
        'customer_phone' => '+59170000000',
    ]));

    expect($bus->dispatched)->toHaveCount(1);

    $command = $bus->dispatched[0];
    expect($command)->toBeInstanceOf(QueueNotification::class);
    /** @var QueueNotification $command */
    expect($command->shipmentId)->toBe('shp-1')
        ->and($command->messageType)->toBe(MessageType::OrderReceived->value)
        ->and($command->email)->toBe('ana@example.com')
        ->and($command->phone)->toBe('+59170000000');
});

it('is idempotent: does not re-queue when a notification already exists for that shipment and type', function (): void {
    $bus = capturingBus();
    $dispatcher = new DomainEventNotificationDispatcher(notificationsExisting(true), $bus);

    $dispatcher->dispatch(outboxMessage('shipments.shipment_received', ['shipment_id' => 'shp-1']));

    expect($bus->dispatched)->toBe([]);
});

it('ignores events that are not in the map', function (): void {
    $bus = capturingBus();
    $dispatcher = new DomainEventNotificationDispatcher(notificationsExisting(false), $bus);

    $dispatcher->dispatch(outboxMessage('shipments.something_else', ['shipment_id' => 'shp-1']));

    expect($bus->dispatched)->toBe([]);
});

it('ignores a mapped event without a shipment id', function (): void {
    $bus = capturingBus();
    $dispatcher = new DomainEventNotificationDispatcher(notificationsExisting(false), $bus);

    $dispatcher->dispatch(outboxMessage('execution.shipment_delivered', ['foo' => 'bar']));

    expect($bus->dispatched)->toBe([]);
});

it('maps delivery failure to the Failed message type', function (): void {
    $bus = capturingBus();
    $dispatcher = new DomainEventNotificationDispatcher(notificationsExisting(false), $bus);

    $dispatcher->dispatch(outboxMessage('execution.delivery_failed', ['shipment_id' => 'shp-9']));

    /** @var QueueNotification $command */
    $command = $bus->dispatched[0];
    expect($command->messageType)->toBe(MessageType::Failed->value);
});
