<?php

declare(strict_types=1);

namespace Logistics\Outbox\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use DateTimeInterface;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Traduce entre la entidad de dominio OutboxMessage y su forma persistida.
 *
 * toRow() devuelve un array listo para el query builder (fechas y JSON ya serializados, porque el
 * insert/update no pasan por los casts del modelo). toEntity() reconstituye desde un registro Eloquent.
 */
final class OutboxMessageMapper
{
    private const DATE_FORMAT = 'Y-m-d H:i:sP';

    /**
     * @return array<string, mixed>
     */
    public static function toRow(OutboxMessage $message): array
    {
        $dispatchedAt = $message->dispatchedAt();

        return [
            'id' => $message->id()->toString(),
            'event_id' => $message->eventId()->value,
            'aggregate_type' => $message->aggregateType(),
            'aggregate_id' => $message->aggregateId(),
            'event_name' => $message->eventName(),
            'payload' => json_encode($message->payload(), JSON_THROW_ON_ERROR),
            'status' => $message->status()->value,
            'attempts' => $message->attempts(),
            'available_at' => $message->availableAt()->format(self::DATE_FORMAT),
            'created_at' => $message->createdAt()->format(self::DATE_FORMAT),
            'dispatched_at' => $dispatchedAt?->format(self::DATE_FORMAT),
            'last_error' => $message->lastError(),
        ];
    }

    public static function toEntity(OutboxMessageRecord $record): OutboxMessage
    {
        $dispatchedAt = $record->dispatched_at;
        $lastError = $record->last_error;

        return OutboxMessage::restore(
            id: OutboxMessageId::fromString($record->id),
            eventId: Uuid::fromString($record->event_id),
            aggregateType: $record->aggregate_type,
            aggregateId: $record->aggregate_id,
            eventName: $record->event_name,
            payload: $record->payload,
            status: OutboxStatus::from($record->status),
            attempts: $record->attempts,
            availableAt: DateTimeImmutable::createFromInterface($record->available_at),
            createdAt: DateTimeImmutable::createFromInterface($record->created_at),
            dispatchedAt: $dispatchedAt instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($dispatchedAt)
                : null,
            lastError: is_string($lastError) ? $lastError : null,
        );
    }
}
