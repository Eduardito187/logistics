<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El canal confirmó la promesa dentro de la ventana de reserva: la entrega queda prometida en firme.
 */
final readonly class PromiseConfirmed extends BaseDomainEvent
{
    public function __construct(
        string $promiseId,
        public string $shipmentRef,
        public string $zoneId,
        public string $slotDate,
        public string $timeSlot,
    ) {
        parent::__construct($promiseId);
    }

    public function eventName(): string
    {
        return 'promise.promise_confirmed';
    }

    public function payload(): array
    {
        return [
            'promise_id' => $this->aggregateId,
            'shipment_ref' => $this->shipmentRef,
            'zone_id' => $this->zoneId,
            'slot_date' => $this->slotDate,
            'time_slot' => $this->timeSlot,
        ];
    }
}
