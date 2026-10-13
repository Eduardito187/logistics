<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application;

use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shared\Domain\Event\DomainEvent;

/**
 * Puente entre los agregados y el outbox.
 *
 * Los contextos liberan sus eventos con AggregateRoot::releaseEvents() y se los entregan a este
 * servicio dentro de la misma transacción que el cambio de negocio. Cada evento se persiste como un
 * mensaje Pending; el relay los despachará después, fuera del request del usuario (principio 4).
 */
final readonly class RecordDomainEvents
{
    public function __construct(
        private OutboxStore $store,
        private Clock $clock,
    ) {}

    /**
     * Encola todos los eventos de un agregado hacia el outbox.
     *
     * @param list<DomainEvent> $events
     * @param string $aggregateType Nombre del tipo de agregado emisor (p. ej. "network.node").
     */
    public function record(array $events, string $aggregateType): void
    {
        $now = $this->clock->now();

        foreach ($events as $event) {
            $this->store->append(OutboxMessage::fromDomainEvent($event, $aggregateType, $now));
        }
    }
}
