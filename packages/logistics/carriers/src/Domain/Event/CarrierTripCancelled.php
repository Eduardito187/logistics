<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * La oficina canceló un viaje programado antes de que saliera: sus envíos quedan libres.
 */
final readonly class CarrierTripCancelled extends BaseDomainEvent
{
    /**
     * @param list<string> $shipmentIds
     */
    public function __construct(
        string $aggregateId,
        public string $carrierId,
        public string $cancelledAt,
        public ?string $reason,
        public array $shipmentIds,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'carriers.carrier_trip_cancelled';
    }

    public function payload(): array
    {
        return [
            'carrier_id' => $this->carrierId,
            'cancelled_at' => $this->cancelledAt,
            'reason' => $this->reason,
            'shipment_ids' => $this->shipmentIds,
        ];
    }
}
