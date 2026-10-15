<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Command\AdvanceCascade;

use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\ValueObject\NotificationId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Escala una notificación al siguiente canal de su cascada (no hubo apertura en el actual). Si la
 * cascada se agota, el agregado queda en Failed. Persiste y libera los eventos.
 *
 * @implements CommandHandler<AdvanceCascade>
 */
final class AdvanceCascadeHandler implements CommandHandler
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof AdvanceCascade) {
            throw NotificationsException::because('AdvanceCascadeHandler solo maneja AdvanceCascade');
        }

        $notification = $this->notifications->findById(NotificationId::fromString($command->notificationId));
        if ($notification === null) {
            throw NotificationsException::because("no existe la notificación {$command->notificationId}");
        }

        $notification->escalate();

        $this->notifications->save($notification);

        $this->recordEvents->record($notification->releaseEvents(), 'notifications.notification');
    }
}
