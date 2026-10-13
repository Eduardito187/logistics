<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El motor de rutas resolvió orden y ETA para un plan: pasó de borrador a propuesta. De acá se alimenta
 * la revisión humana y, más adelante vía outbox, la notificación de ventanas de entrega al cliente.
 */
final readonly class PlanProposed extends BaseDomainEvent
{
    public function __construct(
        string $planId,
        public string $planDate,
        public string $vehicleId,
        public int $stopCount,
    ) {
        parent::__construct($planId);
    }

    public function eventName(): string
    {
        return 'planning.plan_proposed';
    }

    public function payload(): array
    {
        return [
            'plan_id' => $this->aggregateId,
            'plan_date' => $this->planDate,
            'vehicle_id' => $this->vehicleId,
            'stop_count' => $this->stopCount,
        ];
    }
}
