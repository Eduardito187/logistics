<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se quitó un envío de la carga de un viaje programado.
 */
final readonly class CarrierTripShipmentRemoved extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $carrierId,
        public string $shipmentId,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'carriers.carrier_trip_shipment_removed';
    }

    public function payload(): array
    {
        return [
            'carrier_id' => $this->carrierId,
            'shipment_id' => $this->shipmentId,
        ];
    }
}
