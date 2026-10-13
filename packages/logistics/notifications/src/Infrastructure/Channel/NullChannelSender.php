<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Channel;

use Logistics\Notifications\Domain\Contract\ChannelSender;
use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Psr\Log\LoggerInterface;

/**
 * Sender por defecto de todos los canales: no envía nada a ningún sistema externo, solo deja rastro en
 * el log. Es el binding inicial del contrato, pensado para desarrollo y pruebas.
 *
 * Cada canal real (FCM, web push, email, SMS) reemplaza este binding con su propia implementación, que
 * corre en el relay del outbox (asíncrono, idempotente).
 */
final readonly class NullChannelSender implements ChannelSender
{
    public function __construct(
        private ?LoggerInterface $logger = null,
    ) {}

    public function send(Notification $notification, Channel $channel): void
    {
        $this->logger?->debug('Notification discarded by NullChannelSender', [
            'notification_id' => $notification->id()->toString(),
            'shipment_id' => $notification->shipmentId(),
            'message_type' => $notification->messageType()->value,
            'channel' => $channel->value,
            'fcm_topic' => $notification->fcmTopic()->value,
        ]);
    }
}
