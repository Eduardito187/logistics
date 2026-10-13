<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * La entrega entró en preparación: se preparó el primer bulto (EAN → etiqueta → carril).
 */
final readonly class ShipmentPrepared extends BaseDomainEvent
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
        return 'execution.shipment_prepared';
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
