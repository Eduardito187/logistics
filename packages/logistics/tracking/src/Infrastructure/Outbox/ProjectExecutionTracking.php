<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Outbox;

use DateTimeImmutable;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Tracking\Application\Command\ProjectExecutionEvent\ProjectExecutionEvent;

/**
 * Reactor outbox: traduce los hechos de EJECUCIÓN por envío (cargado, salida, entregado, fallido) al
 * avance del seguimiento. Lee el `shipment_id` del payload (el aggregateId del evento de execution es el
 * delivery, no el envío) y despacha ProjectExecutionEvent, que mapea el tipo a estado visible y aplica de
 * forma tolerante (saltea lo que no corresponde al estado actual).
 */
final readonly class ProjectExecutionTracking implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        $payload = $message->payload();
        $shipmentId = is_string($payload['shipment_id'] ?? null) ? $payload['shipment_id'] : '';
        if ($shipmentId === '') {
            return;
        }

        $this->commandBus->dispatch(new ProjectExecutionEvent(
            executionEventType: $message->eventName(),
            shipmentId: $shipmentId,
            occurredAt: $this->occurredAt($message),
        ));
    }

    /**
     * Momento del hecho de ejecución (alimenta `delivered_at`). El mensaje del outbox no persiste el
     * `occurredAt` del evento de dominio, así que se prefiere un `occurred_at` (o `delivered_at`) explícito
     * del payload si el emisor lo trae; si no, el `created_at` del mensaje, que se graba en la MISMA
     * transacción que el hecho (RecordDomainEvents), así que coincide con él salvo capturas offline.
     */
    private function occurredAt(OutboxMessage $message): string
    {
        $payload = $message->payload();
        foreach (['occurred_at', 'delivered_at'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return $message->createdAt()->format(DateTimeImmutable::ATOM);
    }
}
