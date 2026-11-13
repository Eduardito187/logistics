<?php

declare(strict_types=1);

use Logistics\SapConnector\Application\Command\RetrySapNotification\RetrySapNotification;
use Logistics\SapConnector\Application\Command\RetrySapNotification\RetrySapNotificationHandler;
use Logistics\SapConnector\Application\Contract\SapWorkQueue;
use Logistics\SapConnector\Application\Notification\SapNotificationPolicy;
use Logistics\SapConnector\Domain\Contract\SapNotificationRepository;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\Model\SapNotification;
use Logistics\SapConnector\Domain\ValueObject\SapNotificationId;
use Logistics\SapConnector\Domain\ValueObject\SapNotificationStatus;
use Logistics\SapConnector\Domain\ValueObject\SapResponse;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Reintento operativo de un aviso a SAP (acción «Retry» del panel), con dobles en memoria del
 * repositorio, la cola y el reloj: PHP puro, sin Laravel ni base de datos.
 */

/**
 * Reloj fijo para estas pruebas.
 */
function retryHandlerClock(string $at = '2026-11-12T10:00:00+00:00'): Clock
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
    };
}

/**
 * Repositorio en memoria mínimo para el reintento (guarda por id).
 */
function retryHandlerRepository(): SapNotificationRepository
{
    return new class implements SapNotificationRepository
    {
        /** @var array<string, SapNotification> */
        public array $byId = [];

        public int $saves = 0;

        public function nextIdentity(): SapNotificationId
        {
            return SapNotificationId::generate();
        }

        public function registerIfAbsent(SapNotification $notification): bool
        {
            $this->byId[$notification->id()->toString()] = $notification;

            return true;
        }

        public function save(SapNotification $notification): void
        {
            $this->saves++;
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
            return [];
        }
    };
}

/**
 * Cola en memoria que anota los ids re-encolados.
 */
function retryHandlerQueue(): SapWorkQueue
{
    return new class implements SapWorkQueue
    {
        /** @var list<string> */
        public array $notifications = [];

        public function queueNotification(SapNotificationId $id): void
        {
            $this->notifications[] = $id->toString();
        }

        public function queueTransferDispatch(): void {}

        public function queueOrdersImport(): void {}
    };
}

function retryHandlerPolicy(): SapNotificationPolicy
{
    return new SapNotificationPolicy(
        deliveryEnabled: true,
        events: ['execution.shipment_delivered'],
        graceSeconds: 90,
    );
}

/**
 * Crea un aviso fallido ya persistido para que el handler lo reinicie.
 */
function retryHandlerFailedNotification(SapNotificationRepository $repository): SapNotification
{
    $notification = SapNotification::register(
        SapNotificationId::generate(),
        '0199c111-0000-7000-8000-000000000001',
        'execution.shipment_delivered',
        'SHP-1',
        [],
        new DateTimeImmutable('2026-11-12T09:00:00+00:00'),
        true,
        new DateTimeImmutable('2026-11-12T09:00:00+00:00'),
    );
    $notification->recordFailure('SAP caído', null, new DateTimeImmutable('2026-11-12T09:05:00+00:00'), 1, []);
    $repository->save($notification);

    return $notification;
}

it('reinicia un aviso fallido, lo persiste y re-encola su envío con la gracia de la política', function (): void {
    $repository = retryHandlerRepository();
    $queue = retryHandlerQueue();
    $clock = retryHandlerClock();
    $notification = retryHandlerFailedNotification($repository);
    $savesBefore = $repository->saves;

    $handler = new RetrySapNotificationHandler($repository, $queue, retryHandlerPolicy(), $clock);
    $handler->handle(new RetrySapNotification($notification->id()->toString()));

    $reloaded = $repository->findById($notification->id());
    expect($reloaded?->status())->toBe(SapNotificationStatus::Pending)
        ->and($reloaded?->attempts())->toBe(0)
        ->and($reloaded?->deliveryTrace()->lastError)->toBeNull()
        ->and($reloaded?->deliveryTrace()->nextAttemptAt)->toEqual($clock->now()->add(new DateInterval('PT90S')))
        ->and($repository->saves)->toBe($savesBefore + 1)
        ->and($queue->notifications)->toBe([$notification->id()->toString()]);
});

it('lanza si el aviso a reintentar no existe', function (): void {
    $repository = retryHandlerRepository();
    $queue = retryHandlerQueue();

    $handler = new RetrySapNotificationHandler($repository, $queue, retryHandlerPolicy(), retryHandlerClock());

    expect(fn () => $handler->handle(new RetrySapNotification(SapNotificationId::generate()->toString())))
        ->toThrow(SapConnectorException::class, 'no existe')
        ->and($queue->notifications)->toBe([]);
});

it('rechaza manejar un comando que no es RetrySapNotification', function (): void {
    $handler = new RetrySapNotificationHandler(
        retryHandlerRepository(),
        retryHandlerQueue(),
        retryHandlerPolicy(),
        retryHandlerClock(),
    );

    $otherCommand = new class implements Command {};

    expect(fn () => $handler->handle($otherCommand))
        ->toThrow(SapConnectorException::class, 'solo maneja RetrySapNotification');
});

it('no reintenta un aviso ya enviado a SAP y no lo re-encola', function (): void {
    $repository = retryHandlerRepository();
    $queue = retryHandlerQueue();
    $notification = retryHandlerFailedNotification($repository);
    $notification->resetForRetry(new DateTimeImmutable('2026-11-12T09:30:00+00:00'));
    $notification->recordSuccess(new SapResponse(200, 'ok'), new DateTimeImmutable('2026-11-12T09:31:00+00:00'));
    $repository->save($notification);

    $handler = new RetrySapNotificationHandler($repository, $queue, retryHandlerPolicy(), retryHandlerClock());

    expect(fn () => $handler->handle(new RetrySapNotification($notification->id()->toString())))
        ->toThrow(SapConnectorException::class, 'ya enviado')
        ->and($queue->notifications)->toBe([]);
});
