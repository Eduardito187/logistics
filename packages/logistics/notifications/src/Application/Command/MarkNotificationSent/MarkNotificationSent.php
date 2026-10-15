<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Command\MarkNotificationSent;

use Logistics\Shared\Application\Command\Command;

/**
 * Comando para marcar una notificación como enviada por un canal concreto. Lo dispara el relay del
 * outbox cuando el ChannelSender despachó con éxito: cierra la cascada y graba el hecho NotificationSent.
 */
final readonly class MarkNotificationSent implements Command
{
    public function __construct(
        public string $notificationId,
        public string $channel,
    ) {}
}
