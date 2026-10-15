<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El auto-planificador terminó de armar los planes del día: se publica al outbox para que otros
 * contextos reaccionen (p. ej. notificar ventanas de entrega, alertar pedidos sin asignar). Lleva el
 * resumen de la corrida: camiones planificados, paradas totales y pedidos que quedaron sin asignar.
 */
final readonly class DailyPlanGenerated extends BaseDomainEvent
{
    public function __construct(
        string $runId,
        public string $planDate,
        public string $strategy,
        public int $trucksPlanned,
        public int $stopsPlanned,
        public int $unassignedCount,
    ) {
        parent::__construct($runId);
    }

    public function eventName(): string
    {
        return 'planning.plan_generated';
    }

    public function payload(): array
    {
        return [
            'run_id' => $this->aggregateId,
            'plan_date' => $this->planDate,
            'strategy' => $this->strategy,
            'trucks_planned' => $this->trucksPlanned,
            'stops_planned' => $this->stopsPlanned,
            'unassigned_count' => $this->unassignedCount,
        ];
    }
}
