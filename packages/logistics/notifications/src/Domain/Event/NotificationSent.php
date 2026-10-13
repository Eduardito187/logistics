<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Una notificación se envió con éxito por un canal de la cascada. Cierra el ciclo del mensaje: ya no
 * hace falta escalar al canal siguiente.
 */
final readonly class NotificationSent extends BaseDomainEvent
{
    public function __construct(
        string $notificationId,
        public string $shipmentId,
        public string $messageType,
        public string $channel,
    ) {
        parent::__construct($notificationId);
    }

    public function eventName(): string
    {
        return 'notifications.notification_sent';
    }

    public function payload(): array
    {
        return [
            'notification_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'message_type' => $this->messageType,
            'channel' => $this->channel,
        ];
    }
}
