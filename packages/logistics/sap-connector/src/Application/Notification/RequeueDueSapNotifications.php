<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Notification;

use DateInterval;
use Logistics\SapConnector\Application\Contract\SapWorkQueue;
use Logistics\SapConnector\Domain\Contract\SapNotificationRepository;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Barrido de reintentos de avisos a SAP (lo corre el scheduler cada minuto, fuera de transacción).
 *
 * Toma un lote CHICO de avisos Pending/Failed con intentos < máximo y backoff vencido, les corre el
 * próximo intento (lease) para que la siguiente corrida no los duplique, y re-encola su envío en la cola
 * `sap`. No habla con SAP: el envío lo hace el job. Con el conector apagado no re-encola nada (el job
 * los marcaría Skipped de todos modos; así no se gasta la cola).
 */
final readonly class RequeueDueSapNotifications
{
    public function __construct(
        private SapNotificationRepository $notifications,
        private SapWorkQueue $queue,
        private SapNotificationPolicy $policy,
        private Clock $clock,
    ) {}

    /**
     * Devuelve cuántos avisos re-encoló.
     */
    public function run(): int
    {
        if (!$this->policy->deliveryEnabled) {
            return 0;
        }

        $now = $this->clock->now();
        $leaseUntil = $now->add(new DateInterval('PT'.max(60, $this->policy->requeueLeaseSeconds).'S'));

        $requeued = 0;
        foreach ($this->notifications->dueForRetry($this->policy->attempts(), $now, $this->policy->retryBatch) as $notification) {
            $notification->deferUntil($leaseUntil);
            $this->notifications->save($notification);
            $this->queue->queueNotification($notification->id());
            $requeued++;
        }

        return $requeued;
    }
}
