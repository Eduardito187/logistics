<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El envío pasó a estado planificado. El plan concreto (camión, secuencia, ETA) lo decide el contexto
 * de planificación; aquí solo se registra el cambio de estado del envío.
 */
final readonly class ShipmentPlanned extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'shipments.shipment_planned';
    }

    public function payload(): array
    {
        return [
            'shipment_id' => $this->aggregateId,
        ];
    }
}
