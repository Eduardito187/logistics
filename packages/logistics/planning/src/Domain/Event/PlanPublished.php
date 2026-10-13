<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Un humano confirmó el plan: queda publicado y disponible para que la ejecución lo tome. Es el hecho
 * de handoff hacia el contexto de ejecución (vía outbox al integrar los contextos).
 */
final readonly class PlanPublished extends BaseDomainEvent
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
        return 'planning.plan_published';
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
