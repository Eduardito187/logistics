<?php

declare(strict_types=1);

namespace Logistics\Shared\Domain\Aggregate;

use Logistics\Shared\Domain\Event\DomainEvent;

/**
 * Raíz de agregado. Guarda los eventos que ocurren durante una operación y los entrega una sola vez
 * (los "libera") para que la capa de aplicación los mande al outbox dentro de la misma transacción.
 *
 * El dominio nunca publica por sí mismo: solo registra el hecho. Quién lo despacha es Application.
 */
abstract class AggregateRoot
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];

    final protected function recordEvent(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /**
     * Devuelve los eventos pendientes y vacía el buffer. Idempotente: una segunda llamada da [].
     *
     * @return list<DomainEvent>
     */
    final public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    /**
     * @return list<DomainEvent>
     */
    final public function pendingEvents(): array
    {
        return $this->recordedEvents;
    }
}
