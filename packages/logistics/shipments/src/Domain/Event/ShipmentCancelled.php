<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El envío fue anulado antes de completarse (ADR 020). Lo dispara una cancelación de la orden comercial
 * (vía el reactor del outbox) o una anulación operativa. El cupo/slot que el envío tuviera reservado lo
 * libera el contexto que lo reservó (promise/capacity), por fanout del mismo `orders.order_cancelled`
 * (ver §4 del ADR): este evento solo registra el hecho del lado del envío.
 */
final readonly class ShipmentCancelled extends BaseDomainEvent
{
    public function __construct(
        string $shipmentId,
        public ?string $reason = null,
    ) {
        parent::__construct($shipmentId);
    }

    public function eventName(): string
    {
        return 'shipments.shipment_cancelled';
    }

    public function payload(): array
    {
        return [
            'shipment_id' => $this->aggregateId,
            'reason' => $this->reason,
        ];
    }
}
