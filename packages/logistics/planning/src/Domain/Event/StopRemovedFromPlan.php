<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se quitó una parada (envío) de un plan de reparto. Es el hecho que habilita mover un envío entre
 * camiones del mismo día: al reasignarlo, el plan de ORIGEN emite este evento y el de destino recibe la
 * parada. De acá se alimenta, vía outbox, cualquier reacción posterior (recalcular ruta/ETA del origen,
 * notificar el cambio de camión) sin acoplar contextos.
 */
final readonly class StopRemovedFromPlan extends BaseDomainEvent
{
    public function __construct(
        string $planId,
        public string $planDate,
        public string $vehicleId,
        public string $shipmentId,
        public int $stopCount,
    ) {
        parent::__construct($planId);
    }

    public function eventName(): string
    {
        return 'planning.stop_removed_from_plan';
    }

    public function payload(): array
    {
        return [
            'plan_id' => $this->aggregateId,
            'plan_date' => $this->planDate,
            'vehicle_id' => $this->vehicleId,
            'shipment_id' => $this->shipmentId,
            'stop_count' => $this->stopCount,
        ];
    }
}
