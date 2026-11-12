<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El viaje se cerró: la carga llegó al destino del corredor.
 */
final readonly class CarrierTripClosed extends BaseDomainEvent
{
    /**
     * @param list<string> $shipmentIds
     */
    public function __construct(
        string $aggregateId,
        public string $carrierId,
        public string $corridorTo,
        public string $closedAt,
        public ?string $actorId,
        public array $shipmentIds,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'carriers.carrier_trip_closed';
    }

    public function payload(): array
    {
        return [
            'carrier_id' => $this->carrierId,
            'corridor_to' => $this->corridorTo,
            'closed_at' => $this->closedAt,
            'actor_id' => $this->actorId,
            'shipment_ids' => $this->shipmentIds,
        ];
    }
}
