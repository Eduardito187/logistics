<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se solicitó una planificación automática del día: el comando dejó la corrida encolada. El cálculo
 * pesado todavía no ocurrió (lo hace un worker). Sirve de traza/auditoría del disparo.
 */
final readonly class DailyPlanRequested extends BaseDomainEvent
{
    public function __construct(
        string $runId,
        public string $planDate,
        public string $strategy,
    ) {
        parent::__construct($runId);
    }

    public function eventName(): string
    {
        return 'planning.auto_plan_requested';
    }

    public function payload(): array
    {
        return [
            'run_id' => $this->aggregateId,
            'plan_date' => $this->planDate,
            'strategy' => $this->strategy,
        ];
    }
}
