<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Command\RetrySapNotification;

use Logistics\SapConnector\Application\Contract\SapWorkQueue;
use Logistics\SapConnector\Application\Notification\SapNotificationPolicy;
use Logistics\SapConnector\Domain\Contract\SapNotificationRepository;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\ValueObject\SapNotificationId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Reinicia un aviso a SAP y re-encola su envío. No habla con SAP: el envío lo hace el job de la cola
 * `sap`, que se encola al confirmar la transacción del bus (afterCommit), así el job ve el reinicio.
 *
 * @implements CommandHandler<RetrySapNotification>
 */
final readonly class RetrySapNotificationHandler implements CommandHandler
{
    public function __construct(
        private SapNotificationRepository $notifications,
        private SapWorkQueue $queue,
        private SapNotificationPolicy $policy,
        private Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof RetrySapNotification) {
            throw SapConnectorException::because('RetrySapNotificationHandler solo maneja RetrySapNotification');
        }

        $id = SapNotificationId::fromString($command->notificationId);
        $notification = $this->notifications->findById($id);
        if ($notification === null) {
            throw SapConnectorException::because('el aviso a SAP no existe');
        }

        $notification->resetForRetry($this->clock->now(), $this->policy->graceSeconds);
        $this->notifications->save($notification);

        $this->queue->queueNotification($notification->id());
    }
}
