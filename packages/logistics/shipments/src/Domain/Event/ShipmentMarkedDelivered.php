<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El envío quedó ENTREGADO. La prueba de entrega (foto, confirmación, hora) vive en el contexto de
 * ejecución; aquí solo se registra que el envío cerró su ciclo con éxito.
 */
final readonly class ShipmentMarkedDelivered extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'shipments.shipment_marked_delivered';
    }

    public function payload(): array
    {
        return [
            'shipment_id' => $this->aggregateId,
        ];
    }
}
