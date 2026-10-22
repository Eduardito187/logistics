<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Channel;

use Logistics\Notifications\Domain\Contract\ChannelSender;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\RenderedMessage;
use Logistics\Outbox\Infrastructure\Resilience\CircuitBreaker;

/**
 * Enruta el envío al sender concreto del canal pedido (push FCM, Telegram, email, SMS). Es el binding
 * de producción del contrato ChannelSender: unifica los cuatro canales detrás de una sola puerta para
 * que el dispatcher del outbox no conozca cada implementación.
 *
 * Resiliencia (A-5): cada canal pasa por un circuit breaker POR CANAL (clave = Channel->value). Si un
 * canal (p. ej. FCM) se cae y acumula fallos seguidos, su circuito se abre y los envíos por ESE canal
 * cortan rápido (fast-fail) sin gastar el timeout, mientras los otros canales siguen operando. El
 * fast-fail lanza como cualquier fallo: el relay aplica backoff y la cascada avanza de canal al vencer
 * su ventana. El breaker vive en Infrastructure (estado en Valkey); el Domain no lo conoce.
 */
final readonly class RoutingChannelSender implements ChannelSender
{
    private CircuitBreaker $breaker;

    /**
     * @param array<string, ChannelSender> $senders Sender concreto por Channel->value.
     * @param CircuitBreaker|null          $breaker Breaker por canal; null arma el de config (producción).
     */
    public function __construct(
        private array $senders,
        ?CircuitBreaker $breaker = null,
    ) {
        $this->breaker = $breaker ?? CircuitBreaker::forGroup('notifications-channel');
    }

    public function send(Notification $notification, Channel $channel, RenderedMessage $message): void
    {
        $sender = $this->senders[$channel->value] ?? null;
        if ($sender === null) {
            throw NotificationsException::because("no hay un sender configurado para el canal {$channel->value}");
        }

        // Breaker por canal: aísla un canal caído para que no arrastre a los sanos.
        $this->breaker->call(
            $channel->value,
            static function () use ($sender, $notification, $channel, $message): bool {
                $sender->send($notification, $channel, $message);

                return true;
            },
        );
    }
}
