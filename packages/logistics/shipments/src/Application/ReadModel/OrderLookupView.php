<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\ReadModel;

/**
 * Un envío en la respuesta de la consulta de pedidos: la misma forma del envío del listado (con su avance
 * operativo), más la referencia de la orden, el nodo destino con su nombre legible, el seguimiento resumido
 * y si QUIEN CONSULTA puede operarlo (`canProcess`; false = solo consulta).
 */
final readonly class OrderLookupView
{
    public function __construct(
        public ShipmentView $shipment,
        public ?string $orderReference,
        public ?string $destinationNodeName,
        public OrderTrackingView $tracking,
        public bool $canProcess,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $nodeId = $this->shipment->destinationNodeId;

        return [
            ...$this->shipment->toArray(),
            'order_reference' => $this->orderReference,
            'destination_node' => $nodeId !== null
                ? ['id' => $nodeId, 'name' => $this->destinationNodeName]
                : null,
            'tracking' => $this->tracking->toArray(),
            'can_process' => $this->canProcess,
        ];
    }
}
