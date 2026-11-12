<?php

declare(strict_types=1);

use Logistics\SapConnector\Application\Contract\SapWorkQueue;
use Logistics\SapConnector\Application\Notification\DeliverSapNotification;
use Logistics\SapConnector\Application\Notification\RecordSapNotification;
use Logistics\SapConnector\Application\Notification\RequeueDueSapNotifications;
use Logistics\SapConnector\Application\Notification\SapNotificationPolicy;
use Logistics\SapConnector\Domain\Contract\SapClient;
use Logistics\SapConnector\Domain\Contract\SapNotificationRepository;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\Model\SapNotification;
use Logistics\SapConnector\Domain\Model\StockTransfer;
use Logistics\SapConnector\Domain\ValueObject\SapNotificationId;
use Logistics\SapConnector\Domain\ValueObject\SapNotificationStatus;
use Logistics\SapConnector\Domain\ValueObject\SapResponse;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Avisos fire-and-forget a SAP (registro + envío + reintentos) con dobles en memoria del repositorio, la
 * cola, el puerto SAP y el reloj: PHP puro, sin Laravel ni base de datos.
 */

/**
 * Reloj controlable para estas pruebas.
 */
function sapNotifClock(string $at = '2026-11-12T10:00:00+00:00'): Clock
{
    return new class($at) implements Clock
    {
        public DateTimeImmutable $now;

        public function __construct(string $at)
        {
            $this->now = new DateTimeImmutable($at);
        }

        public function now(): DateTimeImmutable
        {
            return $this->now;
        }

        public function advanceMinutes(int $minutes): void
        {
            $this->now = $this->now->add(new DateInterval("PT{$minutes}M"));
        }
    };
}

/**
 * Repositorio en memoria con la misma idempotencia por event_id que el índice único real.
 */
function sapNotifRepository(): SapNotificationRepository
{
    return new class implements SapNotificationRepository
    {
        /** @var array<string, SapNotification> */
        public array $byId = [];

        public function nextIdentity(): SapNotificationId
        {
            return SapNotificationId::generate();
        }

        public function registerIfAbsent(SapNotification $notification): bool
        {
            if ($this->findByEventId($notification->eventId()) !== null) {
                return false;
            }
            $this->byId[$notification->id()->toString()] = $notification;

            return true;
        }

        public function save(SapNotification $notification): void
        {
            $this->byId[$notification->id()->toString()] = $notification;
        }

        public function findById(SapNotificationId $id): ?SapNotification
        {
            return $this->byId[$id->toString()] ?? null;
        }

        public function findByEventId(string $eventId): ?SapNotification
        {
            foreach ($this->byId as $notification) {
                if ($notification->eventId() === $eventId) {
                    return $notification;
                }
            }

            return null;
        }

        public function dueForRetry(int $maxAttempts, DateTimeImmutable $now, int $limit): array
        {
            $due = array_filter(
                $this->byId,
                static fn (SapNotification $n): bool => $n->canAttempt($maxAttempts)
                    && ($n->nextAttemptAt() === null || $n->nextAttemptAt() <= $now),
            );

            return array_slice(array_values($due), 0, $limit);
        }
    };
}

/**
 * Cola en memoria: guarda los ids encolados.
 */
function sapNotifQueue(): SapWorkQueue
{
    return new class implements SapWorkQueue
    {
        /** @var list<string> */
        public array $notifications = [];

        public int $transferRuns = 0;

        public int $imports = 0;

        public function queueNotification(SapNotificationId $id): void
        {
            $this->notifications[] = $id->toString();
        }

        public function queueTransferDispatch(): void
        {
            $this->transferRuns++;
        }

        public function queueOrdersImport(): void
        {
            $this->imports++;
        }
    };
}

/**
 * Puerto SAP en memoria: responde con el código dado o lanza (simula red caída), y cuenta las llamadas.
 */
function sapNotifClient(int $status = 200, string $body = '{"ok":true}', bool $throws = false): SapClient
{
    return new class($status, $body, $throws) implements SapClient
    {
        public int $calls = 0;

        public function __construct(
            private readonly int $status,
            private readonly string $body,
            private readonly bool $throws,
        ) {}

        public function pushStockTransfer(StockTransfer $transfer): void
        {
            // Sin efecto: este flujo solo ejercita notifyEvent; los traspasos de stock no se usan aquí.
        }

        public function fetchOrders(): array
        {
            return [];
        }

        public function notifyEvent(SapNotification $notification): SapResponse
        {
            $this->calls++;
            if ($this->throws) {
                throw SapConnectorException::because('SAP timeout');
            }

            return new SapResponse($this->status, $this->body);
        }
    };
}

function sapNotifPolicy(bool $enabled = true): SapNotificationPolicy
{
    return new SapNotificationPolicy(
        deliveryEnabled: $enabled,
        events: ['execution.shipment_delivered', 'shipments.shipment_received'],
        maxAttempts: 3,
        backoffMinutes: [1 => 1, 2 => 5],
    );
}

it('registra UN aviso por event_id y encola su envío una sola vez (reentrega del outbox)', function (): void {
    $repository = sapNotifRepository();
    $queue = sapNotifQueue();
    $record = new RecordSapNotification($repository, $queue, sapNotifPolicy(), sapNotifClock());

    $first = $record->record('0199c000-0000-7000-8000-000000000001', 'execution.shipment_delivered', 'SHP-1', ['a' => 1], new DateTimeImmutable);
    $again = $record->record('0199c000-0000-7000-8000-000000000001', 'execution.shipment_delivered', 'SHP-1', ['a' => 1], new DateTimeImmutable);

    expect($first)->toBeTrue()
        ->and($again)->toBeFalse()
        ->and($repository->byId)->toHaveCount(1)
        ->and($queue->notifications)->toHaveCount(1)
        ->and(array_values($repository->byId)[0]->status())->toBe(SapNotificationStatus::Pending);
});

it('ignora los eventos que no están en la lista de eventos a avisar', function (): void {
    $repository = sapNotifRepository();
    $queue = sapNotifQueue();
    $record = new RecordSapNotification($repository, $queue, sapNotifPolicy(), sapNotifClock());

    $recorded = $record->record('0199c000-0000-7000-8000-000000000002', 'fleet.vehicle_registered', null, [], new DateTimeImmutable);

    expect($recorded)->toBeFalse()
        ->and($repository->byId)->toBe([])
        ->and($queue->notifications)->toBe([]);
});

it('con el conector apagado (o sin base_url) registra el aviso como skipped y no encola nada', function (): void {
    $repository = sapNotifRepository();
    $queue = sapNotifQueue();
    $record = new RecordSapNotification($repository, $queue, sapNotifPolicy(enabled: false), sapNotifClock());

    $record->record('0199c000-0000-7000-8000-000000000003', 'shipments.shipment_received', 'SHP-3', [], new DateTimeImmutable);

    $notification = $repository->findByEventId('0199c000-0000-7000-8000-000000000003');
    expect($notification?->status())->toBe(SapNotificationStatus::Skipped)
        ->and($queue->notifications)->toBe([]);

    // Ni el barrido de reintentos lo toma.
    $requeue = new RequeueDueSapNotifications($repository, $queue, sapNotifPolicy(enabled: false), sapNotifClock());
    expect($requeue->run())->toBe(0);
});

it('el envío marca sent y guarda el código y el cuerpo crudo de la respuesta de SAP', function (): void {
    $repository = sapNotifRepository();
    $queue = sapNotifQueue();
    $clock = sapNotifClock();
    (new RecordSapNotification($repository, $queue, sapNotifPolicy(), $clock))
        ->record('0199c000-0000-7000-8000-000000000004', 'execution.shipment_delivered', 'SHP-4', [], new DateTimeImmutable);
    $id = SapNotificationId::fromString($queue->notifications[0]);

    $sap = sapNotifClient(202, '{"received":true}');
    $status = (new DeliverSapNotification($repository, $sap, sapNotifPolicy(), $clock))->deliver($id);

    $notification = $repository->findById($id);
    expect($status)->toBe(SapNotificationStatus::Sent)
        ->and($notification?->attempts())->toBe(1)
        ->and($notification?->responseStatus())->toBe(202)
        ->and($notification?->responseBody())->toBe('{"received":true}')
        ->and($notification?->sentAt())->toEqual($clock->now())
        ->and($notification?->lastError())->toBeNull();

    // Reentrega del job: ya enviado, no se vuelve a llamar a SAP.
    (new DeliverSapNotification($repository, $sap, sapNotifPolicy(), $clock))->deliver($id);
    expect($sap->calls)->toBe(1);
});

it('trunca la respuesta cruda de SAP a ~4 KB', function (): void {
    $repository = sapNotifRepository();
    $queue = sapNotifQueue();
    (new RecordSapNotification($repository, $queue, sapNotifPolicy(), sapNotifClock()))
        ->record('0199c000-0000-7000-8000-000000000005', 'execution.shipment_delivered', null, [], new DateTimeImmutable);
    $id = SapNotificationId::fromString($queue->notifications[0]);

    (new DeliverSapNotification($repository, sapNotifClient(200, str_repeat('x', 10000)), sapNotifPolicy(), sapNotifClock()))->deliver($id);

    expect(strlen((string) $repository->findById($id)?->responseBody()))->toBe(SapNotification::RESPONSE_BODY_LIMIT);
});

it('un fallo de SAP nunca lanza y se deja de insistir tras 3 intentos (queda failed)', function (): void {
    $repository = sapNotifRepository();
    $queue = sapNotifQueue();
    $clock = sapNotifClock();
    (new RecordSapNotification($repository, $queue, sapNotifPolicy(), $clock))
        ->record('0199c000-0000-7000-8000-000000000006', 'execution.shipment_delivered', null, [], new DateTimeImmutable);
    $id = SapNotificationId::fromString($queue->notifications[0]);

    $sap = sapNotifClient(throws: true);
    $deliver = new DeliverSapNotification($repository, $sap, sapNotifPolicy(), $clock);
    $requeue = new RequeueDueSapNotifications($repository, $queue, sapNotifPolicy(), $clock);

    // Intento 1 (job del registro): falla, backoff 1 min.
    expect($deliver->deliver($id))->toBe(SapNotificationStatus::Failed);
    expect($repository->findById($id)?->nextAttemptAt())->toEqual($clock->now()->add(new DateInterval('PT1M')));

    // Antes de vencer el backoff el barrido no lo toma.
    expect($requeue->run())->toBe(0);

    // Intento 2: vence el backoff, el barrido lo re-encola y el job vuelve a fallar (backoff 5 min).
    $clock->advanceMinutes(1);
    expect($requeue->run())->toBe(1);
    $deliver->deliver($id);

    // Intento 3: último.
    $clock->advanceMinutes(5);
    expect($requeue->run())->toBe(1);
    $deliver->deliver($id);

    $notification = $repository->findById($id);
    expect($notification?->status())->toBe(SapNotificationStatus::Failed)
        ->and($notification?->attempts())->toBe(3)
        ->and($notification?->nextAttemptAt())->toBeNull()
        ->and($notification?->lastError())->toContain('SAP timeout')
        ->and($sap->calls)->toBe(3);

    // Agotado: ni el barrido lo vuelve a tomar ni un job tardío vuelve a llamar a SAP.
    $clock->advanceMinutes(60);
    expect($requeue->run())->toBe(0);
    $deliver->deliver($id);
    expect($sap->calls)->toBe(3);
});

it('una respuesta no 2xx cuenta como intento fallido y guarda la respuesta', function (): void {
    $repository = sapNotifRepository();
    $queue = sapNotifQueue();
    (new RecordSapNotification($repository, $queue, sapNotifPolicy(), sapNotifClock()))
        ->record('0199c000-0000-7000-8000-000000000007', 'execution.shipment_delivered', null, [], new DateTimeImmutable);
    $id = SapNotificationId::fromString($queue->notifications[0]);

    (new DeliverSapNotification($repository, sapNotifClient(503, 'maintenance'), sapNotifPolicy(), sapNotifClock()))->deliver($id);

    $notification = $repository->findById($id);
    expect($notification?->status())->toBe(SapNotificationStatus::Failed)
        ->and($notification?->attempts())->toBe(1)
        ->and($notification?->responseStatus())->toBe(503)
        ->and($notification?->responseBody())->toBe('maintenance');
});

it('si el conector se apaga entre el registro y el envío, el aviso queda skipped sin llamar a SAP', function (): void {
    $repository = sapNotifRepository();
    $queue = sapNotifQueue();
    (new RecordSapNotification($repository, $queue, sapNotifPolicy(), sapNotifClock()))
        ->record('0199c000-0000-7000-8000-000000000008', 'execution.shipment_delivered', null, [], new DateTimeImmutable);
    $id = SapNotificationId::fromString($queue->notifications[0]);

    $sap = sapNotifClient();
    (new DeliverSapNotification($repository, $sap, sapNotifPolicy(enabled: false), sapNotifClock()))->deliver($id);

    expect($repository->findById($id)?->status())->toBe(SapNotificationStatus::Skipped)
        ->and($sap->calls)->toBe(0);
});

it('el reintento operativo reinicia los intentos de un aviso fallido y rechaza reenviar uno enviado', function (): void {
    $notification = SapNotification::register(
        SapNotificationId::generate(),
        '0199c000-0000-7000-8000-000000000009',
        'execution.shipment_delivered',
        null,
        [],
        new DateTimeImmutable,
        true,
        new DateTimeImmutable,
    );
    $notification->recordFailure('down', null, new DateTimeImmutable, 1, []);

    expect($notification->canAttempt(1))->toBeFalse();

    $notification->resetForRetry(new DateTimeImmutable);
    expect($notification->status())->toBe(SapNotificationStatus::Pending)
        ->and($notification->attempts())->toBe(0)
        ->and($notification->canAttempt(1))->toBeTrue();

    $notification->recordSuccess(new SapResponse(200, ''), new DateTimeImmutable);
    expect(fn () => $notification->resetForRetry(new DateTimeImmutable))->toThrow(SapConnectorException::class);
});
