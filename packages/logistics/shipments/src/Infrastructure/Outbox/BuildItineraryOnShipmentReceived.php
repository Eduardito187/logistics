<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Outbox;

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Application\Command\BuildShipmentItinerary\BuildShipmentItinerary;
use Throwable;

/**
 * Reactor outbox: cuando se recibe un envío (`shipments.shipment_received`) construye su itinerario
 * (decide propia/tercerizado por departamento destino y arma los tramos).
 *
 * El id del envío viaja como `shipment_id` en el payload (o el aggregateId del evento, que es el id del
 * envío). Tolerante e idempotente: el handler reconstruye y reemplaza, así que reprocesar el mensaje no
 * duplica. Nunca lanza: armar el itinerario es best-effort y hay un comando de reconstrucción para
 * reparar datos; un fallo acá no debe tumbar el fanout del evento.
 */
final readonly class BuildItineraryOnShipmentReceived implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'shipments.shipment_received') {
            return;
        }

        $payload = $message->payload();
        $shipmentId = is_string($payload['shipment_id'] ?? null) ? $payload['shipment_id'] : $message->aggregateId();
        if ($shipmentId === '') {
            return;
        }

        try {
            $this->commandBus->dispatch(new BuildShipmentItinerary($shipmentId));
        } catch (Throwable) {
            // Best-effort: un fallo al construir el itinerario no debe interrumpir el resto del fanout.
        }
    }
}
