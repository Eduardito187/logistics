<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Logistics\SapConnector\Application\Notification\DeliverSapNotification;
use Logistics\SapConnector\Domain\ValueObject\SapNotificationId;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Job de la cola `sap`: envía UN aviso fire-and-forget a SAP (DeliverSapNotification).
 *
 * Lleva solo el id (primitivo): en handle() relee el aviso para operar sobre el estado más fresco. La
 * política de reintentos/backoff vive en la fila (`attempts`/`next_attempt_at`) y la aplica el barrido
 * del scheduler, no el reintento de cola de Laravel: por eso $tries = 1. NUNCA lanza: cualquier error
 * (incluida la base) se loguea y se traga, para que nada de SAP llegue a afectar a la plataforma.
 */
final class SendSapNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Un solo intento de cola: el reintento lo decide la fila, no la cola. */
    public int $tries = 1;

    /** Tope duro del job: el HTTP a SAP tiene su propio timeout corto (5s por defecto). */
    public int $timeout = 60;

    public function __construct(
        public readonly string $notificationId,
    ) {}

    public function handle(DeliverSapNotification $deliver, LoggerInterface $logger): void
    {
        try {
            $deliver->deliver(SapNotificationId::fromString($this->notificationId));
        } catch (Throwable $error) {
            $logger->warning('SAP notification delivery crashed; it will be retried by the sweep if attempts remain', [
                'notification_id' => $this->notificationId,
                'error' => $error->getMessage(),
            ]);
        }
    }
}
