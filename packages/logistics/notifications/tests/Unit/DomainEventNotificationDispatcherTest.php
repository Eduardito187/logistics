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
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\Event\DomainEvent;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Application\Query\FindShipment\FindShipment;
use Logistics\Shipments\Application\ReadModel\ShipmentView;

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

/**
 * Bus de consultas que responde el envío con la modalidad dada (o lanza, si `$mode` es 'boom') y cuenta las
 * lecturas hechas.
 */
function shipmentModeBus(?string $mode): QueryBus
{
    return new class($mode) implements QueryBus
    {
        public int $asked = 0;

        public function __construct(private ?string $mode) {}

        public function ask(Query $query): mixed
        {
            $this->asked++;
            if ($this->mode === 'boom') {
                throw new RuntimeException('consulta caída');
            }
            if ($this->mode === null) {
                return null;
            }

            return new ShipmentView(
                id: $query instanceof FindShipment ? $query->shipmentId : '',
                source: $this->mode === 'transfer' ? 'TRANSFER' : 'MAGENTO',
                externalId: 'ORD-1',
                customerName: 'Ana',
                customerDocumentId: null,
                customerPhone: null,
                customerEmail: null,
                addressText: 'Av. Siempre Viva 1',
                addressReference: null,
                lat: null,
                lng: null,
                addressConfidenceScore: 0,
                addressConfidenceLevel: 'LOW',
                status: 'IN_EXECUTION',
                deliveryDate: null,
                deliveryWindowStart: null,
                deliveryWindowEnd: null,
                destinationNodeId: null,
                lines: [],
                deliveryMode: $this->mode,
            );
        }
    };
}

it('does not queue any customer notification for an internal transfer', function (string $eventName): void {
    $bus = capturingBus();
    $queries = shipmentModeBus('transfer');
    $dispatcher = new DomainEventNotificationDispatcher(notificationsExisting(false), $bus, $queries);

    $dispatcher->dispatch(outboxMessage($eventName, ['shipment_id' => '019a8f3e-6b1c-7d2a-9e4f-0a1b2c3d4e5f']));

    expect($bus->dispatched)->toBe([])
        ->and($queries->asked)->toBe(1);
})->with([
    'execution.shipment_delivered',
    'execution.shipment_loaded',
    'execution.shipment_prepared',
    'execution.delivery_failed',
    'tracking.status_changed',
    'shipments.shipment_received',
]);

it('still queues the notification for customer shipments', function (string $mode): void {
    $bus = capturingBus();
    $dispatcher = new DomainEventNotificationDispatcher(notificationsExisting(false), $bus, shipmentModeBus($mode));

    $dispatcher->dispatch(outboxMessage('execution.shipment_delivered', ['shipment_id' => '019a8f3e-6b1c-7d2a-9e4f-0a1b2c3d4e5f']));

    expect($bus->dispatched)->toHaveCount(1);
    /** @var QueueNotification $command */
    $command = $bus->dispatched[0];
    expect($command->messageType)->toBe(MessageType::Delivered->value);
})->with(['home_delivery', 'store_pickup']);

it('behaves as before when the shipment lookup fails or finds nothing', function (?string $mode): void {
    $bus = capturingBus();
    $dispatcher = new DomainEventNotificationDispatcher(notificationsExisting(false), $bus, shipmentModeBus($mode));

    $dispatcher->dispatch(outboxMessage('execution.shipment_delivered', ['shipment_id' => '019a8f3e-6b1c-7d2a-9e4f-0a1b2c3d4e5f']));

    expect($bus->dispatched)->toHaveCount(1);
})->with([
    'consulta caída' => ['boom'],
    'envío inexistente' => [null],
]);

it('does not read the shipment when the notification already exists or the id is not a uuid', function (): void {
    $bus = capturingBus();
    $queries = shipmentModeBus('transfer');

    (new DomainEventNotificationDispatcher(notificationsExisting(true), $bus, $queries))
        ->dispatch(outboxMessage('execution.shipment_delivered', ['shipment_id' => '019a8f3e-6b1c-7d2a-9e4f-0a1b2c3d4e5f']));
    (new DomainEventNotificationDispatcher(notificationsExisting(false), $bus, $queries))
        ->dispatch(outboxMessage('execution.shipment_delivered', ['shipment_id' => 'shp-1']));

    expect($queries->asked)->toBe(0)
        ->and($bus->dispatched)->toHaveCount(1);
});
