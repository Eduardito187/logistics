<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Command\SuppressNotification;

use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\ValueObject\NotificationId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Suprime una notificación encolada: deja de participar en la cascada. No genera envío (no hay hecho
 * de dominio que despachar), solo persiste el nuevo estado.
 *
 * @implements CommandHandler<SuppressNotification>
 */
final class SuppressNotificationHandler implements CommandHandler
{
    public function __construct(
        private readonly NotificationRepository $notifications,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof SuppressNotification) {
            throw NotificationsException::because('SuppressNotificationHandler solo maneja SuppressNotification');
        }

        $notification = $this->notifications->findById(NotificationId::fromString($command->notificationId));
        if ($notification === null) {
            throw NotificationsException::because("no existe la notificación {$command->notificationId}");
        }

        $notification->suppress();

        $this->notifications->save($notification);
    }
}
