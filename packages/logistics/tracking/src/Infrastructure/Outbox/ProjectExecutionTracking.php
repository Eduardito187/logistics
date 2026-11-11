<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Outbox;

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
        ));
    }
}
