<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El chofer DEJÓ el pedido en la tienda: escaneó los productos y sacó la foto de entrega. No es la entrega
 * al cliente (eso pasa después, en el mostrador): es el pase del camión a la tienda. Desde este hecho el
 * pedido está «listo para retirar» y se le avisa al cliente que ya puede pasar a recogerlo.
 */
final readonly class ShipmentDroppedAtStore extends BaseDomainEvent
{
    public function __construct(
        string $deliveryId,
        public string $shipmentId,
        public string $nodeId,
        public string $vehicleId,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($deliveryId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.shipment_dropped_at_store';
    }

    public function payload(): array
    {
        return [
            'delivery_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'node_id' => $this->nodeId,
            'destination_node_id' => $this->nodeId,
            'vehicle_id' => $this->vehicleId,
        ];
    }
}
