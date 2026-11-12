<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Notification;

use DateTimeImmutable;
use Logistics\SapConnector\Application\Contract\SapWorkQueue;
use Logistics\SapConnector\Domain\Contract\SapNotificationRepository;
use Logistics\SapConnector\Domain\Model\SapNotification;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Caso de uso: registrar (de nuestro lado) el aviso a SAP de un hecho y encolar su envío.
 *
 * - Solo actúa si el evento está en la lista configurada de «eventos a avisar»; el resto se ignora.
 * - Idempotente por event_id: el outbox reentrega at-least-once y el observador corre en cada
 *   procesamiento, así que el mismo hecho puede llegar varias veces; se registra UNA sola fila
 *   (insert-or-ignore) y solo la primera vez se encola el envío.
 * - Conector apagado o sin URL base ⇒ la fila nace Skipped y no se encola nada.
 *
 * No toca SAP: solo escribe una fila y empuja un job a la cola `sap`. No corre dentro de una
 * transacción de negocio (lo invoca el observador del outbox, después del despacho).
 */
final readonly class RecordSapNotification
{
    public function __construct(
        private SapNotificationRepository $notifications,
        private SapWorkQueue $queue,
        private SapNotificationPolicy $policy,
        private Clock $clock,
    ) {}

    /**
     * Devuelve true si registró un aviso nuevo (false si el evento no se avisa o ya estaba registrado).
     *
     * @param array<string, mixed> $payload
     */
    public function record(
        string $eventId,
        string $eventName,
        ?string $aggregateId,
        array $payload,
        DateTimeImmutable $occurredAt,
    ): bool {
        if (!$this->policy->notifies($eventName)) {
            return false;
        }

        $notification = SapNotification::register(
            $this->notifications->nextIdentity(),
            $eventId,
            $eventName,
            $aggregateId,
            $payload,
            $occurredAt,
            $this->policy->deliveryEnabled,
            $this->clock->now(),
            $this->policy->graceSeconds,
        );

        if (!$this->notifications->registerIfAbsent($notification)) {
            return false;
        }

        if ($this->policy->deliveryEnabled) {
            // Si el encolado fallara, la fila queda Pending y el barrido de reintentos la retoma al vencer
            // la gracia: el aviso nunca se pierde y el llamador (observador) traga el error.
            $this->queue->queueNotification($notification->id());
        }

        return true;
    }
}
