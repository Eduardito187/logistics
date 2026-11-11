<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El camión SALIÓ con la entrega: el chofer inició la ruta. Es el hecho que separa «cargada» (en el
 * camión, aún en el CD) de «en camino» (ya en ruta hacia el cliente). Lo proyecta el seguimiento.
 */
final readonly class ShipmentDeparted extends BaseDomainEvent
{
    public function __construct(
        string $deliveryId,
        public string $shipmentId,
        public string $vehicleId,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($deliveryId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.shipment_departed';
    }

    public function payload(): array
    {
        return [
            'delivery_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'vehicle_id' => $this->vehicleId,
        ];
    }
}
