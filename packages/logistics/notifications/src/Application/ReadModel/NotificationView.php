<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\ReadModel;

use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\Channel;

/**
 * Modelo de lectura de una notificación: forma plana en primitivos para APIs, listados y paneles.
 *
 * Se arma desde el agregado con fromNotification(); las consultas nunca exponen el objeto de dominio.
 */
final readonly class NotificationView
{
    /**
     * @param list<string>       $channels Canales de la política, en orden.
     * @param array<string, int> $attempts Intentos por canal.
     */
    public function __construct(
        public string $id,
        public string $shipmentId,
        public string $messageType,
        public string $status,
        public string $currentChannel,
        public string $fcmTopic,
        public array $channels,
        public array $attempts,
        // Referencia externa humana del envío (tabla shipments.external_id) resuelta en el QueryHandler.
        // Null si el envío asociado ya no existe; el UUID crudo se mantiene en shipment_id.
        public ?string $shipmentExternalId = null,
        // Fecha de creación (ISO 8601) y estado de lectura DEL USUARIO que consulta (null = no leída).
        // Los resuelve el QueryHandler en lote por página (NotificationInboxReader); no son del agregado.
        public ?string $createdAt = null,
        public ?string $readAt = null,
    ) {}

    public static function fromNotification(
        Notification $notification,
        ?string $shipmentExternalId = null,
        ?string $createdAt = null,
        ?string $readAt = null,
    ): self {
        return new self(
            $notification->id()->toString(),
            $notification->shipmentId(),
            $notification->messageType()->value,
            $notification->status()->value,
            $notification->currentChannel()->value,
            $notification->fcmTopic()->value,
            array_map(static fn (Channel $c): string => $c->value, $notification->policy()->channels),
            $notification->attempts(),
            $shipmentExternalId,
            $createdAt,
            $readAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'shipment_id' => $this->shipmentId,
            'shipment_external_id' => $this->shipmentExternalId,
            'message_type' => $this->messageType,
            'status' => $this->status,
            'current_channel' => $this->currentChannel,
            'fcm_topic' => $this->fcmTopic,
            'channels' => $this->channels,
            'attempts' => $this->attempts,
            'created_at' => $this->createdAt,
            'read_at' => $this->readAt,
        ];
    }
}
