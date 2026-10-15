<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Command\MarkNotificationSent;

use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\NotificationId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Marca una notificación como enviada por un canal: cuenta el intento, cierra la cascada y libera el
 * hecho NotificationSent. Persiste en la misma transacción que el registro de eventos.
 *
 * @implements CommandHandler<MarkNotificationSent>
 */
final class MarkNotificationSentHandler implements CommandHandler
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof MarkNotificationSent) {
            throw NotificationsException::because('MarkNotificationSentHandler solo maneja MarkNotificationSent');
        }

        $notification = $this->notifications->findById(NotificationId::fromString($command->notificationId));
        if ($notification === null) {
            throw NotificationsException::because("no existe la notificación {$command->notificationId}");
        }

        $notification->markSentVia(Channel::from($command->channel));

        $this->notifications->save($notification);

        $this->recordEvents->record($notification->releaseEvents(), 'notifications.notification');
    }
}
