<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se CERRÓ un TRAMO del itinerario del envío al dejar la carga en un nodo intermedio (un `to_node_id` que
 * NO es el destino final del itinerario) o en el almacén de la transportadora (handoff). NO es la entrega:
 * el envío sigue su recorrido por el próximo tramo.
 *
 * El nodo se referencia por id string (no se acopla a network). `seq` es la posición del tramo cerrado
 * dentro del itinerario (tabla `shipment_legs`). El contexto de envíos reacciona a este hecho para pasar
 * ese tramo a `closed` y arrancar el siguiente (ver snippet del coordinador).
 */
final readonly class ShipmentLegClosed extends BaseDomainEvent
{
    public function __construct(
        string $deliveryId,
        public string $shipmentId,
        public int $seq,
        public string $nodeId,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($deliveryId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.shipment_leg_closed';
    }

    public function payload(): array
    {
        return [
            'delivery_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'seq' => $this->seq,
            'node_id' => $this->nodeId,
            // Hora real del cierre del tramo (capturas offline incluidas).
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
        ];
    }
}
