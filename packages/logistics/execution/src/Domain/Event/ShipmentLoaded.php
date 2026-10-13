<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * La entrega quedó cargada al vehículo: se validó la carga del primer bulto.
 */
final readonly class ShipmentLoaded extends BaseDomainEvent
{
    public function __construct(
        string $deliveryId,
        public string $shipmentId,
        public string $vehicleId,
        public string $unitId,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($deliveryId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.shipment_loaded';
    }

    public function payload(): array
    {
        return [
            'delivery_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'vehicle_id' => $this->vehicleId,
            'unit_id' => $this->unitId,
        ];
    }
}
