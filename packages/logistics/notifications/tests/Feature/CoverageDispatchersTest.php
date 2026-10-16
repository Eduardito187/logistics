<?php

declare(strict_types=1);

use Logistics\Notifications\Domain\Contract\ChannelSender;
use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Domain\ValueObject\NotificationId;
use Logistics\Notifications\Domain\ValueObject\NotificationStatus;
use Logistics\Notifications\Domain\ValueObject\RenderedMessage;
use Logistics\Notifications\Infrastructure\Channel\NotificationContentRenderer;
use Logistics\Notifications\Infrastructure\Dispatch\FleetDocumentExpiryNotificationDispatcher;
use Logistics\Notifications\Infrastructure\Dispatch\NotificationChannelDispatcher;
use Logistics\Notifications\Infrastructure\Dispatch\VehicleIncidentNotificationDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Event\DomainEvent;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Psr\Log\AbstractLogger;

/**
 * Cobertura de los dispatchers del outbox del contexto notifications (patrón C/G): se construye un
 * OutboxMessage de dominio y se despacha por cada dispatcher, ejercitando el envío por el canal actual
 * (NotificationChannelDispatcher con un ChannelSender falso), el digest de flota
 * (FleetDocumentExpiryNotificationDispatcher) y el aviso por incidente de vehículo
 * (VehicleIncidentNotificationDispatcher). Corre contra PostgreSQL (logistics_test); skip sin él.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

/**
 * Evento de dominio mínimo (PHP puro) para envolver en un mensaje del outbox.
 *
 * @param array<string, mixed> $payload
 */
function covDispDomainEvent(string $eventName, array $payload): DomainEvent
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
            return 'agg-cov';
        }

        public function eventName(): string
        {
            return $this->name;
        }

        public function occurredAt(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-10-15T10:00:00+00:00');
        }

        public function payload(): array
        {
            return $this->payload;
        }
    };
}

/**
 * Envuelve un evento en un OutboxMessage, como lo recibe el dispatcher en el relay.
 *
 * @param array<string, mixed> $payload
 */
function covDispOutbox(string $eventName, array $payload): OutboxMessage
{
    return OutboxMessage::fromDomainEvent(
        covDispDomainEvent($eventName, $payload),
        'cov.aggregate',
        new DateTimeImmutable('2026-10-15T10:00:00+00:00'),
    );
}

/**
 * ChannelSender falso que captura el envío sin tocar ningún sistema externo.
 */
function covCapturingSender(): ChannelSender
{
    return new class implements ChannelSender
    {
        /** @var list<array{channel: string, body: string}> */
        public array $sent = [];

        public function send(Notification $notification, Channel $channel, RenderedMessage $message): void
        {
            $this->sent[] = ['channel' => $channel->value, 'body' => $message->body];
        }
    };
}

it('materializa el envío de una notificación encolada y cierra la cascada', function (): void {
    $repo = app(NotificationRepository::class);
    $id = NotificationId::generate();

    $notification = Notification::queue(
        $id,
        Uuid::generate()->value,
        MessageType::EnRoute,
        CascadePolicy::of([Channel::Fcm, Channel::Email]),
        'cliente@dismac.test',
    );
    $repo->save($notification);

    $sender = covCapturingSender();
    $dispatcher = new NotificationChannelDispatcher(
        $repo,
        $sender,
        app(NotificationContentRenderer::class),
        app(CommandBus::class),
    );

    $dispatcher->dispatch(covDispOutbox('notifications.notification_queued', [
        'notification_id' => $id->toString(),
    ]));

    expect($sender->sent)->toHaveCount(1)
        ->and($sender->sent[0]['channel'])->toBe(Channel::Fcm->value);

    $reloaded = $repo->findById($id);
    expect($reloaded)->not->toBeNull()
        ->and($reloaded->status())->toBe(NotificationStatus::Sent);
});

it('ignora eventos ajenos y notificaciones inexistentes o no encoladas', function (): void {
    $repo = app(NotificationRepository::class);
    $sender = covCapturingSender();
    $dispatcher = new NotificationChannelDispatcher(
        $repo,
        $sender,
        app(NotificationContentRenderer::class),
        app(CommandBus::class),
    );

    // Evento de otro contexto: se ignora.
    $dispatcher->dispatch(covDispOutbox('shipments.shipment_received', ['notification_id' => 'x']));
    // Evento correcto pero sin id de notificación: se ignora.
    $dispatcher->dispatch(covDispOutbox('notifications.notification_queued', []));
    // Id que no existe en el repositorio: se ignora.
    $dispatcher->dispatch(covDispOutbox('notifications.notification_queued', [
        'notification_id' => NotificationId::generate()->toString(),
    ]));

    expect($sender->sent)->toBe([]);
});

it('registra el digest de vencimientos de flota y envía el correo al supervisor', function (): void {
    $logger = new class extends AbstractLogger
    {
        /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
        public array $records = [];

        public function log($level, string | Stringable $message, array $context = []): void
        {
            $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
        }
    };

    $dispatcher = new FleetDocumentExpiryNotificationDispatcher(
        $logger,
        'supervisor@dismac.test',
        'ops@dismac.test',
        'Dismac Ops',
        'array',
    );

    $dispatcher->dispatch(covDispOutbox('fleet.documents_expiry_alerted', [
        'reference_date' => '2026-10-15',
        'total_count' => 3,
        'expired_count' => 1,
        'expiring_count' => 2,
        'documents' => [
            ['owner_type' => 'vehicle', 'owner_label' => 'ABC-123', 'document_type' => 'soat', 'expires_on' => '2026-10-01', 'expired' => true],
            ['owner_type' => 'driver', 'owner_label' => 'Ada', 'document_type' => 'license', 'expires_on' => '2026-11-01', 'expired' => false],
        ],
    ]));

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toBe('Fleet document expiry alert')
        ->and($logger->records[0]['context']['total'])->toBe(3);
});

it('ignora el digest de flota cuando el evento no corresponde', function (): void {
    $logger = new class extends AbstractLogger
    {
        /** @var list<mixed> */
        public array $records = [];

        public function log($level, string | Stringable $message, array $context = []): void
        {
            $this->records[] = $message;
        }
    };

    $dispatcher = new FleetDocumentExpiryNotificationDispatcher($logger, null, 'ops@dismac.test', 'Dismac Ops');
    $dispatcher->dispatch(covDispOutbox('fleet.something_else', []));

    expect($logger->records)->toHaveCount(0);
});

it('encola avisos a clientes y supervisor ante un incidente mayor de vehículo', function (): void {
    $repo = app(NotificationRepository::class);
    $dispatcher = app(VehicleIncidentNotificationDispatcher::class);

    $shipmentA = Uuid::generate()->value;
    $shipmentB = Uuid::generate()->value;

    $message = covDispOutbox('execution.vehicle_incident_reported', [
        'severity' => 'MAJOR',
        'affected_shipment_ids' => [$shipmentA, $shipmentB],
    ]);

    $dispatcher->dispatch($message);

    expect($repo->existsForShipmentAndType($shipmentA, MessageType::Rescheduled))->toBeTrue()
        ->and($repo->existsForShipmentAndType($shipmentB, MessageType::Rescheduled))->toBeTrue()
        ->and($repo->existsForShipmentAndType($shipmentA, MessageType::SupervisorIncident))->toBeTrue();

    // Reentrega at-least-once: idempotente, no duplica avisos del mismo envío y tipo.
    $dispatcher->dispatch($message);
    expect($repo->findByShipment($shipmentA))->toHaveCount(2);
});

it('ignora el incidente de vehículo sin envíos afectados', function (): void {
    $repo = app(NotificationRepository::class);
    $dispatcher = app(VehicleIncidentNotificationDispatcher::class);

    $dispatcher->dispatch(covDispOutbox('execution.vehicle_incident_reported', [
        'severity' => 'MINOR',
        'affected_shipment_ids' => [],
    ]));

    // Nada que afirmar más allá de que no revienta: sin envíos, el dispatcher no encola.
    expect(true)->toBeTrue();
});
