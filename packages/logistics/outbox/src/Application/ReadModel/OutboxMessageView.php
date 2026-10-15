<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\ReadModel;

use Logistics\Outbox\Domain\OutboxMessage;

/**
 * Modelo de lectura de un mensaje del outbox: forma plana y primitiva, lista para serializar a JSON.
 * No expone objetos de dominio; si se cachea, se cachea su toArray() (regla CLAUDE.md #7).
 */
final readonly class OutboxMessageView
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $id,
        public string $eventId,
        public string $aggregateType,
        public string $aggregateId,
        public string $eventName,
        public array $payload,
        public string $status,
        public int $attempts,
        public string $availableAt,
        public string $createdAt,
        public ?string $dispatchedAt,
        public ?string $lastError,
    ) {}

    public static function fromMessage(OutboxMessage $message): self
    {
        return new self(
            $message->id()->toString(),
            $message->eventId()->value,
            $message->aggregateType(),
            $message->aggregateId(),
            $message->eventName(),
            $message->payload(),
            $message->status()->value,
            $message->attempts(),
            $message->availableAt()->format(DATE_ATOM),
            $message->createdAt()->format(DATE_ATOM),
            $message->dispatchedAt()?->format(DATE_ATOM),
            $message->lastError(),
        );
    }

    /**
     * @return array{id: string, event_id: string, aggregate_type: string, aggregate_id: string, event_name: string, payload: array<string, mixed>, status: string, attempts: int, available_at: string, created_at: string, dispatched_at: string|null, last_error: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->eventId,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'event_name' => $this->eventName,
            'payload' => $this->payload,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'available_at' => $this->availableAt,
            'created_at' => $this->createdAt,
            'dispatched_at' => $this->dispatchedAt,
            'last_error' => $this->lastError,
        ];
    }
}
