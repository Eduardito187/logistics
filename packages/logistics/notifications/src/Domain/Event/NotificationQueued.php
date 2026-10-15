<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se encoló una notificación para un envío. De este hecho se alimenta el outbox, que despacha el
 * mensaje por el primer canal de la cascada (push ntfy al topic del envío).
 */
final readonly class NotificationQueued extends BaseDomainEvent
{
    /**
     * @param list<string> $channels Canales de la política, en orden.
     */
    public function __construct(
        string $notificationId,
        public string $shipmentId,
        public string $messageType,
        public string $firstChannel,
        public string $fcmTopic,
        public array $channels,
    ) {
        parent::__construct($notificationId);
    }

    public function eventName(): string
    {
        return 'notifications.notification_queued';
    }

    public function payload(): array
    {
        return [
            'notification_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'message_type' => $this->messageType,
            'first_channel' => $this->firstChannel,
            'fcm_topic' => $this->fcmTopic,
            'channels' => $this->channels,
        ];
    }
}
