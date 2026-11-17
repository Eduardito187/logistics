<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * NUESTRO lado dejó la carga en el almacén de la transportadora (nodo `carrier_hub`): el envío pasa a manos
 * del tercero. Decisión del dueño: el "entregado a transportadora" se marca con ESTE handoff propio (escaneo
 * nuestro), NO cuando la transportadora arranca su viaje.
 *
 * NO es la entrega final al cliente: el envío sigue su recorrido. Tracking reacciona a este hecho para pasar
 * el estado visible a HANDED_TO_CARRIER (el cableado del reactor lo hace el coordinador).
 *
 * El nodo y la transportadora se referencian por id string (no se acopla a network ni a carriers);
 * `carrierId` puede ser null si el tramo todavía no tiene transportadora asignada.
 */
final readonly class ShipmentHandedToCarrier extends BaseDomainEvent
{
    public function __construct(
        string $deliveryId,
        public string $shipmentId,
        public ?string $carrierId,
        public string $nodeId,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($deliveryId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.shipment_handed_to_carrier';
    }

    public function payload(): array
    {
        return [
            'delivery_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'carrier_id' => $this->carrierId,
            'node_id' => $this->nodeId,
            // Hora real del handoff (capturas offline incluidas).
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
        ];
    }
}
