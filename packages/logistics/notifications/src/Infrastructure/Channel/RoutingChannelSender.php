<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Channel;

use Logistics\Notifications\Domain\Contract\ChannelSender;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\RenderedMessage;

/**
 * Enruta el envío al sender concreto del canal pedido (push ntfy, web push, Telegram, email, SMS). Es el binding
 * de producción del contrato ChannelSender: unifica los cuatro canales detrás de una sola puerta para
 * que el dispatcher del outbox no conozca cada implementación.
 */
final readonly class RoutingChannelSender implements ChannelSender
{
    /**
     * @param array<string, ChannelSender> $senders Sender concreto por Channel->value.
     */
    public function __construct(
        private array $senders,
    ) {}

    public function send(Notification $notification, Channel $channel, RenderedMessage $message): void
    {
        $sender = $this->senders[$channel->value] ?? null;
        if ($sender === null) {
            throw NotificationsException::because("no hay un sender configurado para el canal {$channel->value}");
        }

        $sender->send($notification, $channel, $message);
    }
}
