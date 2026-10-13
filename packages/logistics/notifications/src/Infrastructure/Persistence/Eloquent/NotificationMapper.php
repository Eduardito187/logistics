<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Persistence\Eloquent;

use Logistics\Notifications\Domain\Model\Notification;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Domain\ValueObject\NotificationId;
use Logistics\Notifications\Domain\ValueObject\NotificationStatus;

/**
 * Traduce entre el agregado Notification y su fila Eloquent. Toda la conversión a/desde primitivos vive
 * acá, para que ni el dominio ni el repositorio conozcan la forma de la tabla.
 */
final class NotificationMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toRow(Notification $notification): array
    {
        return [
            'id' => $notification->id()->toString(),
            'shipment_id' => $notification->shipmentId(),
            'message_type' => $notification->messageType()->value,
            'status' => $notification->status()->value,
            'current_channel' => $notification->currentChannel()->value,
            'policy' => $notification->policy()->toArray(),
            'attempts' => $notification->attempts(),
        ];
    }

    public function toDomain(NotificationRecord $record): Notification
    {
        /** @var array{channels: list<string>, escalate_when?: string} $policyData */
        $policyData = $record->policy;

        /** @var array<string, int> $attempts */
        $attempts = $record->attempts;

        return Notification::reconstitute(
            NotificationId::fromString($record->id),
            $record->shipment_id,
            MessageType::from($record->message_type),
            CascadePolicy::fromArray($policyData),
            NotificationStatus::from($record->status),
            Channel::from($record->current_channel),
            $attempts,
        );
    }
}
