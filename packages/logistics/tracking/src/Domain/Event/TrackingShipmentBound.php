<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se ligó el envío real al seguimiento del pedido: el tracking nació por `orderReference` (al crear la
 * orden, estado pendiente) y aquí, cuando el pago crea el envío, se le asocia su `shipmentId` interno.
 * A partir de este hecho el tracking puede ubicarse en la ruta y recibir los eventos de ejecución.
 */
final readonly class TrackingShipmentBound extends BaseDomainEvent
{
    public function __construct(
        string $trackingId,
        public string $shipmentId,
        public ?string $orderReference,
    ) {
        parent::__construct($trackingId);
    }

    public function eventName(): string
    {
        return 'tracking.shipment_bound';
    }

    public function payload(): array
    {
        return [
            'tracking_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'order_reference' => $this->orderReference,
        ];
    }
}
