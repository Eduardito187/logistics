<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se agregaron envíos a la carga de un viaje programado (solo los nuevos, no los ya presentes).
 */
final readonly class CarrierTripShipmentsAssigned extends BaseDomainEvent
{
    /**
     * @param list<string> $shipmentIds
     */
    public function __construct(
        string $aggregateId,
        public string $carrierId,
        public array $shipmentIds,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'carriers.carrier_trip_shipments_assigned';
    }

    public function payload(): array
    {
        return [
            'carrier_id' => $this->carrierId,
            'shipment_ids' => $this->shipmentIds,
        ];
    }
}
