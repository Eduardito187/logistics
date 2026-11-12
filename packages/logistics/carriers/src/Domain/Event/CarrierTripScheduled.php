<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * La oficina programó un viaje para un transportista (corredor origen→destino y fecha de salida).
 */
final readonly class CarrierTripScheduled extends BaseDomainEvent
{
    /**
     * @param list<string> $shipmentIds
     */
    public function __construct(
        string $aggregateId,
        public string $carrierId,
        public string $corridorFrom,
        public string $corridorTo,
        public string $scheduledAt,
        public ?string $reference,
        public array $shipmentIds,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'carriers.carrier_trip_scheduled';
    }

    public function payload(): array
    {
        return [
            'carrier_id' => $this->carrierId,
            'corridor_from' => $this->corridorFrom,
            'corridor_to' => $this->corridorTo,
            'scheduled_at' => $this->scheduledAt,
            'reference' => $this->reference,
            'shipment_ids' => $this->shipmentIds,
        ];
    }
}
