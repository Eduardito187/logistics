<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * La ventana de reserva venció antes de confirmar: la promesa expira y su cupo debe liberarse en
 * capacity. El relay del outbox se encarga de la liberación real, de forma idempotente.
 */
final readonly class PromiseExpired extends BaseDomainEvent
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
        return 'promise.promise_expired';
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
