<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Command\MarkNotificationRead;

use Logistics\Notifications\Domain\Contract\NotificationReadStateRepository;
use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\ValueObject\NotificationId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Marca una notificación como leída para el usuario. No toca el agregado Notification (la lectura es
 * estado del usuario, no del envío al cliente) ni genera eventos: solo persiste el estado de lectura.
 *
 * @implements CommandHandler<MarkNotificationRead>
 */
final class MarkNotificationReadHandler implements CommandHandler
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly NotificationReadStateRepository $readStates,
        private readonly Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof MarkNotificationRead) {
            throw NotificationsException::because('MarkNotificationReadHandler solo maneja MarkNotificationRead');
        }

        $userId = trim($command->userId);
        if ($userId === '') {
            throw NotificationsException::because('marcar como leída necesita un usuario');
        }

        $id = NotificationId::fromString($command->notificationId);
        if ($this->notifications->findById($id) === null) {
            throw NotificationsException::because("no existe la notificación {$command->notificationId}");
        }

        $this->readStates->markRead($id, $userId, $this->clock->now());
    }
}
