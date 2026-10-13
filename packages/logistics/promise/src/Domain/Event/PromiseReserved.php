<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se reservó temporalmente el cupo de una promesa. De este hecho se alimentan capacity (descuento de
 * cupo) y, más adelante, el outbox hacia otros contextos.
 */
final readonly class PromiseReserved extends BaseDomainEvent
{
    public function __construct(
        string $promiseId,
        public string $shipmentRef,
        public string $zoneId,
        public string $slotDate,
        public string $timeSlot,
        public string $reservedUntil,
    ) {
        parent::__construct($promiseId);
    }

    public function eventName(): string
    {
        return 'promise.promise_reserved';
    }

    public function payload(): array
    {
        return [
            'promise_id' => $this->aggregateId,
            'shipment_ref' => $this->shipmentRef,
            'zone_id' => $this->zoneId,
            'slot_date' => $this->slotDate,
            'time_slot' => $this->timeSlot,
            'reserved_until' => $this->reservedUntil,
        ];
    }
}
