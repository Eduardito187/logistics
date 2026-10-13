<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Al envío se le asignó (o se le cambió) la franja de entrega comprometida: un día más una ventana
 * horaria. De este hecho se alimentan el cupo de despacho (capacity), la promesa al cliente y la
 * planificación diaria, todos aguas abajo vía outbox.
 */
final readonly class ShipmentDeliverySlotAssigned extends BaseDomainEvent
{
    public function __construct(
        string $shipmentId,
        public string $deliveryDate,
        public string $windowStart,
        public string $windowEnd,
    ) {
        parent::__construct($shipmentId);
    }

    public function eventName(): string
    {
        return 'shipments.shipment_delivery_slot_assigned';
    }

    public function payload(): array
    {
        return [
            'shipment_id' => $this->aggregateId,
            'delivery_date' => $this->deliveryDate,
            'window_start' => $this->windowStart,
            'window_end' => $this->windowEnd,
        ];
    }
}
