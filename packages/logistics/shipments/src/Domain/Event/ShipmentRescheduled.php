<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se REPROGRAMÓ la entrega de un envío: cambió su fecha/ventana (y, si aplica, el lugar). Ocurre tras el
 * pago y antes de entregar. Lo proyecta el seguimiento como «reprogramada» y dispara el aviso al cliente.
 */
final readonly class ShipmentRescheduled extends BaseDomainEvent
{
    public function __construct(
        string $shipmentId,
        public string $deliveryDate,
        public string $windowStart,
        public string $windowEnd,
        public ?string $reason = null,
    ) {
        parent::__construct($shipmentId);
    }

    public function eventName(): string
    {
        return 'shipments.shipment_rescheduled';
    }

    public function payload(): array
    {
        return [
            'shipment_id' => $this->aggregateId,
            'delivery_date' => $this->deliveryDate,
            'window_start' => $this->windowStart,
            'window_end' => $this->windowEnd,
            'reason' => $this->reason,
        ];
    }
}
