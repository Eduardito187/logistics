<?php

declare(strict_types=1);

use Logistics\SapConnector\Application\Notification\DeliverSapNotification;
use Logistics\SapConnector\Application\Notification\SapNotificationPolicy;
use Logistics\SapConnector\Domain\Contract\SapClient;
use Logistics\SapConnector\Domain\Contract\SapNotificationRepository;
use Logistics\SapConnector\Domain\Model\SapNotification;
use Logistics\SapConnector\Domain\Model\StockTransfer;
use Logistics\SapConnector\Domain\ValueObject\SapNotificationId;
use Logistics\SapConnector\Domain\ValueObject\SapResponse;
use Logistics\SapConnector\Infrastructure\Queue\SendSapNotificationJob;
use Logistics\Shared\Domain\Clock\Clock;
use Psr\Log\AbstractLogger;

/**
 * Job de la cola `sap` que envía un aviso: NUNCA lanza. Se ejercitan el camino normal (delega en
 * DeliverSapNotification) y la red de seguridad (si el envío revienta, loguea y se lo traga). PHP puro.
 */

/**
 * Logger espía en memoria.
 */
function sendJobLogger(): AbstractLogger
{
    return new class extends AbstractLogger
    {
        /** @var list<array{level: mixed, message: string}> */
        public array $records = [];

        public function log($level, $message, array $context = []): void
        {
            $this->records[] = ['level' => $level, 'message' => (string) $message];
        }
    };
}

function sendJobClock(): Clock
{
    return new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-11-12T10:00:00+00:00');
        }
    };
}

function sendJobPolicy(): SapNotificationPolicy
{
    return new SapNotificationPolicy(deliveryEnabled: true, events: ['execution.shipment_delivered']);
}

/**
 * Puerto SAP mínimo (no se llega a usar en estas pruebas del job).
 */
function sendJobSapClient(): SapClient
{
    return new class implements SapClient
    {
        public function pushStockTransfer(StockTransfer $transfer): void {}

        public function fetchOrders(): array
        {
            return [];
        }

        public function notifyEvent(SapNotification $notification): SapResponse
        {
            return new SapResponse(204, '');
        }
    };
}

it('delega en DeliverSapNotification sin loguear nada cuando el aviso no existe (idempotente)', function (): void {
    $repository = new class implements SapNotificationRepository
    {
        public function nextIdentity(): SapNotificationId
        {
            return SapNotificationId::generate();
        }

        public function registerIfAbsent(SapNotification $notification): bool
        {
            return true;
        }

        public function save(SapNotification $notification): void {}

        public function findById(SapNotificationId $id): ?SapNotification
        {
            return null;
        }

        public function findByEventId(string $eventId): ?SapNotification
        {
            return null;
        }

        public function dueForRetry(int $maxAttempts, DateTimeImmutable $now, int $limit): array
        {
            return [];
        }
    };

    $deliver = new DeliverSapNotification($repository, sendJobSapClient(), sendJobPolicy(), sendJobClock());
    $logger = sendJobLogger();

    (new SendSapNotificationJob(SapNotificationId::generate()->toString()))->handle($deliver, $logger);

    expect($logger->records)->toBe([]);
});

it('se traga cualquier error del envío y lo deja en el log (nunca lanza hacia la cola)', function (): void {
    $repository = new class implements SapNotificationRepository
    {
        public function nextIdentity(): SapNotificationId
        {
            return SapNotificationId::generate();
        }

        public function registerIfAbsent(SapNotification $notification): bool
        {
            return true;
        }

        public function save(SapNotification $notification): void {}

        public function findById(SapNotificationId $id): ?SapNotification
        {
            throw new RuntimeException('la base no responde');
        }

        public function findByEventId(string $eventId): ?SapNotification
        {
            return null;
        }

        public function dueForRetry(int $maxAttempts, DateTimeImmutable $now, int $limit): array
        {
            return [];
        }
    };

    $deliver = new DeliverSapNotification($repository, sendJobSapClient(), sendJobPolicy(), sendJobClock());
    $logger = sendJobLogger();

    $job = new SendSapNotificationJob(SapNotificationId::generate()->toString());

    // No debe propagar la excepción: la traga y la loguea.
    $job->handle($deliver, $logger);

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('warning')
        ->and($logger->records[0]['message'])->toContain('SAP notification delivery crashed');
});

it('un solo intento de cola: el reintento lo decide la fila, no la cola', function (): void {
    $job = new SendSapNotificationJob('0199c333-0000-7000-8000-000000000001');

    expect($job->tries)->toBe(1)
        ->and($job->notificationId)->toBe('0199c333-0000-7000-8000-000000000001');
});
