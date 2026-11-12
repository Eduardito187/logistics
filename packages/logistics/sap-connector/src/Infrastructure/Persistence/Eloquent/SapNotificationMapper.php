<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use DateTimeInterface;
use Logistics\SapConnector\Domain\Model\SapNotification;
use Logistics\SapConnector\Domain\ValueObject\SapNotificationId;
use Logistics\SapConnector\Domain\ValueObject\SapNotificationStatus;

/**
 * Traduce entre el agregado SapNotification y su fila `sap_notifications`.
 */
final class SapNotificationMapper
{
    private const DATE_FORMAT = 'Y-m-d H:i:sP';

    /**
     * Fila de estado mutable (lo que cambia al enviar/fallar/reintentar).
     *
     * @return array{status: string, attempts: int, last_error: string|null, response_status: int|null, response_body: string|null, sent_at: string|null, next_attempt_at: string|null}
     */
    public function toStateRow(SapNotification $notification): array
    {
        return [
            'status' => $notification->status()->value,
            'attempts' => $notification->attempts(),
            'last_error' => $notification->lastError(),
            'response_status' => $notification->responseStatus(),
            'response_body' => $notification->responseBody(),
            'sent_at' => $notification->sentAt()?->format(self::DATE_FORMAT),
            'next_attempt_at' => $notification->nextAttemptAt()?->format(self::DATE_FORMAT),
        ];
    }

    /**
     * Fila completa para el insert-or-ignore (crudo: el payload va codificado a JSON a mano porque
     * insertOrIgnore no pasa por los casts del modelo).
     *
     * @return array<string, mixed>
     */
    public function toInsertRow(SapNotification $notification, DateTimeImmutable $now): array
    {
        $payload = json_encode((object) $notification->payload(), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return [
            'id' => $notification->id()->toString(),
            'event_id' => $notification->eventId(),
            'event_name' => $notification->eventName(),
            'aggregate_id' => $notification->aggregateId(),
            'payload' => $payload === false ? '{}' : $payload,
            'occurred_at' => $notification->occurredAt()->format(self::DATE_FORMAT),
            ...$this->toStateRow($notification),
            'created_at' => $now->format(self::DATE_FORMAT),
            'updated_at' => $now->format(self::DATE_FORMAT),
        ];
    }

    public function toDomain(SapNotificationRecord $record): SapNotification
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($record->payload) ? $record->payload : [];

        return SapNotification::restore(
            SapNotificationId::fromString($record->id),
            $record->event_id,
            $record->event_name,
            $record->aggregate_id,
            $payload,
            self::date($record->occurred_at) ?? self::date($record->created_at) ?? new DateTimeImmutable,
            SapNotificationStatus::tryFrom($record->status) ?? SapNotificationStatus::Failed,
            $record->attempts,
            $record->last_error,
            $record->response_status,
            $record->response_body,
            self::date($record->sent_at),
            self::date($record->next_attempt_at),
        );
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        return $value instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($value) : null;
    }
}
