<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Dispatch;

use Logistics\Notifications\Application\Command\MarkNotificationSent\MarkNotificationSent;
use Logistics\Notifications\Domain\Contract\ChannelSender;
use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\ValueObject\NotificationId;
use Logistics\Notifications\Domain\ValueObject\NotificationStatus;
use Logistics\Notifications\Infrastructure\Channel\NotificationContentRenderer;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Dispatcher del outbox para los hechos del contexto notifications (P1-NOT-07): materializa el envío
 * real por el canal actual de la cascada cuando el relay procesa un `notifications.notification_queued`.
 *
 * Al consumir el evento: reconstruye la notificación, renderiza el contenido del canal actual y lo
 * envía por el ChannelSender (push ntfy, web push, Telegram, email o SMS). Si el envío sale bien, despacha el
 * comando MarkNotificationSent para cerrar la cascada. Si falla, deja propagar la excepción: el relay
 * aplica backoff; el salto de canal lo dispara AdvanceCascade al vencer la ventana sin apertura.
 *
 * Es idempotente: si la notificación ya no está Queued (ya enviada o suprimida), no hace nada; el
 * mismo evento puede reentregarse (at-least-once).
 *
 * Solo atiende eventos cuyo nombre empieza con `notifications.`; cualquier otro lo ignora, para poder
 * componerse con los dispatchers de otros contextos sin pisarlos.
 */
final readonly class NotificationChannelDispatcher implements MessageDispatcher
{
    public function __construct(
        private NotificationRepository $notifications,
        private ChannelSender $channels,
        private NotificationContentRenderer $renderer,
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'notifications.notification_queued') {
            return;
        }

        $payload = $message->payload();
        $notificationId = $payload['notification_id'] ?? null;
        if (!is_string($notificationId) || $notificationId === '') {
            return;
        }

        $notification = $this->notifications->findById(NotificationId::fromString($notificationId));
        if ($notification === null || $notification->status() !== NotificationStatus::Queued) {
            return;
        }

        $channel = $notification->currentChannel();
        $rendered = $this->renderer->render($notification, $channel);

        // El envío real: si falla, lanza y el relay reintenta/aplica backoff.
        $this->channels->send($notification, $channel, $rendered);

        // Envío exitoso: cierra la cascada por el comando (misma transacción outbox del handler).
        $this->commandBus->dispatch(new MarkNotificationSent($notificationId, $channel->value));
    }
}
