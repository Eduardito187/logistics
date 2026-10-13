<?php

declare(strict_types=1);

namespace Logistics\Shared\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Base cómoda para eventos concretos: resuelve eventId y occurredAt, y deja solo el nombre y la carga.
 *
 * Un evento concreto extiende esto, recibe sus datos en el constructor y define eventName()/payload().
 */
abstract readonly class BaseDomainEvent implements DomainEvent
{
    public Uuid $eventId;

    public DateTimeImmutable $occurredAt;

    public function __construct(
        public string $aggregateId,
        ?Uuid $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        $this->eventId = $eventId ?? Uuid::generate();
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable();
    }

    public function eventId(): Uuid
    {
        return $this->eventId;
    }

    public function aggregateId(): string
    {
        return $this->aggregateId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
