<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se asignó AUTOMÁTICAMENTE una ventana de entrega a un envío cuyo cliente solo había elegido la fecha
 * (Módulo 1). De este hecho se alimentan las notificaciones al cliente ("tu ventana 09:00-13:00") y, si
 * está activado, el aviso fire-and-forget a SAP. El agregado emisor es el envío (su id).
 */
final readonly class DeliveryWindowAssigned extends BaseDomainEvent
{
    public function __construct(
        string $shipmentId,
        public string $slotDate,
        public string $timeSlot,
        public string $windowStart,
        public string $windowEnd,
        public string $slotSource,
    ) {
        parent::__construct($shipmentId);
    }

    public function eventName(): string
    {
        return 'promise.delivery_window_assigned';
    }

    public function payload(): array
    {
        return [
            'shipment_id' => $this->aggregateId,
            'slot_date' => $this->slotDate,
            'time_slot' => $this->timeSlot,
            'window_start' => $this->windowStart,
            'window_end' => $this->windowEnd,
            'slot_source' => $this->slotSource,
        ];
    }
}
