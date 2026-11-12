<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * La entrega de un envío se reasignó de un camión a otro (el pedido pasó al ÚLTIMO camión asignado). Solo
 * ocurre mientras la entrega está en curso (no entregada ni fallida): una entrega REAL ya cerrada nunca se
 * mueve. Deja rastro para el ciclo de vida/auditoría y para que tracking/notifications reaccionen.
 */
final readonly class DeliveryVehicleReassigned extends BaseDomainEvent
{
    public function __construct(
        string $deliveryId,
        public string $shipmentId,
        public string $fromVehicleId,
        public string $toVehicleId,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($deliveryId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.delivery_vehicle_reassigned';
    }

    public function payload(): array
    {
        return [
            'delivery_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'from_vehicle_id' => $this->fromVehicleId,
            'to_vehicle_id' => $this->toVehicleId,
        ];
    }
}
