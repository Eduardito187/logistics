<?php

declare(strict_types=1);

namespace Logistics\Inbound\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se agendó la cita del ASN: día y franja de recepción en el andén (cupo reservado en capacity).
 */
final readonly class AsnScheduled extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $warehouseId,
        public string $appointmentDate,
        public string $timeSlot,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'inbound.asn_scheduled';
    }

    public function payload(): array
    {
        return [
            'warehouse_id' => $this->warehouseId,
            'appointment_date' => $this->appointmentDate,
            'time_slot' => $this->timeSlot,
        ];
    }
}
