<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se borró un plan de reparto SIN publicar (borrador o propuesta), con todas sus paradas.
 *
 * Lleva los envíos que quedaron liberados: dejan de figurar en un plan y vuelven a ser candidatos para
 * armar otro viaje. Como el plan nunca se publicó, sus envíos no habían cambiado de estado (pasan a
 * planificados recién al publicar), así que no hay nada que revertir en ellos. De acá se alimenta, vía
 * outbox, cualquier reacción posterior sin acoplar contextos.
 */
final readonly class PlanDeleted extends BaseDomainEvent
{
    /**
     * @param list<string> $shipmentIds envíos de las paradas que tenía el plan, en orden de reparto
     */
    public function __construct(
        string $planId,
        public string $planDate,
        public string $vehicleId,
        public array $shipmentIds,
    ) {
        parent::__construct($planId);
    }

    public function eventName(): string
    {
        return 'planning.plan_deleted';
    }

    public function payload(): array
    {
        return [
            'plan_id' => $this->aggregateId,
            'plan_date' => $this->planDate,
            'vehicle_id' => $this->vehicleId,
            'shipment_ids' => $this->shipmentIds,
            'stop_count' => count($this->shipmentIds),
        ];
    }
}
