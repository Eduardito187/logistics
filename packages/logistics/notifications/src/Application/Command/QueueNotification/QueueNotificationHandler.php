<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Command\QueueNotification;

use Logistics\Notifications\Application\Contract\NotificationRulesProvider;
use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Domain\ValueObject\NotificationId;
use Logistics\Notifications\Domain\ValueObject\Recipient;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Encola una notificación: resuelve la política de cascada desde las reglas del tipo de mensaje, arma
 * el agregado, lo persiste y libera los eventos de dominio.
 *
 * Deja el id de la última notificación encolada en lastNotificationId().
 *
 * @implements CommandHandler<QueueNotification>
 */
final class QueueNotificationHandler implements CommandHandler
{
    private ?NotificationId $lastNotificationId = null;

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly NotificationRulesProvider $rules,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof QueueNotification) {
            throw NotificationsException::because('QueueNotificationHandler solo maneja QueueNotification');
        }

        $messageType = MessageType::from($command->messageType);

        // Valida los contactos de respaldo al encolar (el canal primario va por topic FCM del envío).
        $recipient = Recipient::of($command->shipmentId, $command->email, $command->phone);

        $policy = $this->rules->policyFor($messageType);

        $notification = Notification::queue(
            $this->notifications->nextIdentity(),
            $recipient->shipmentId,
            $messageType,
            $policy,
        );

        $this->notifications->save($notification);
        $this->lastNotificationId = $notification->id();

        // TODO(outbox): despachar estos eventos al outbox dentro de la misma transacción al integrar.
        $notification->releaseEvents();
    }

    /**
     * Id de la notificación encolada tras el último handle().
     */
    public function lastNotificationId(): NotificationId
    {
        if ($this->lastNotificationId === null) {
            throw NotificationsException::because('todavía no se encoló ninguna notificación en este handler');
        }

        return $this->lastNotificationId;
    }
}
