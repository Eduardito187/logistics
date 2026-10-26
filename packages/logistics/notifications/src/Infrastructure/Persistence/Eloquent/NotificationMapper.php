<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Persistence\Eloquent;

use Logistics\Notifications\Domain\Exception\NotificationsException;
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
            'email' => $notification->email(),
            'phone' => $notification->phone(),
            'telegram_chat_id' => $notification->telegramChatId(),
        ];
    }

    public function toDomain(NotificationRecord $record): Notification
    {
        /** @var array{channels: list<string>, escalate_when?: string} $policyData */
        $policyData = $record->policy;

        /** @var array<string, int> $storedAttempts */
        $storedAttempts = $record->attempts;

        // Lectura tolerante: las filas viejas pueden traer alias históricos del canal (PUSH_FCM,
        // PUSH_NTFY). Un canal actual irreconocible no es reconstruible: se lanza y el repositorio
        // decide omitir la fila con log, sin tumbar el listado.
        $currentChannel = Channel::fromStored($record->current_channel)
            ?? throw NotificationsException::because(
                "la notificación {$record->id} tiene un canal actual desconocido: {$record->current_channel}",
            );

        $attempts = [];
        foreach ($storedAttempts as $channel => $count) {
            $known = Channel::fromStored((string) $channel);
            if ($known !== null) {
                $attempts[$known->value] = ($attempts[$known->value] ?? 0) + (int) $count;
            }
        }

        return Notification::reconstitute(
            NotificationId::fromString($record->id),
            $record->shipment_id,
            MessageType::from($record->message_type),
            CascadePolicy::fromStored($policyData),
            NotificationStatus::from($record->status),
            $currentChannel,
            $attempts,
            $record->email,
            $record->phone,
            $record->telegram_chat_id,
        );
    }
}
