<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Queue;

use Logistics\Planning\Domain\Contract\DailyPlanDispatcher;
use Logistics\Planning\Domain\ValueObject\AutoPlanParameters;
use Logistics\Planning\Domain\ValueObject\AutoPlanRunId;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;

/**
 * Implementación del puerto de encolado sobre la cola de Laravel (Valkey). Encola el cálculo pesado
 * DESPUÉS del commit de la transacción del comando (afterCommit), para que el worker nunca arranque
 * sobre una corrida que todavía no se confirmó en base.
 */
final readonly class QueuedDailyPlanDispatcher implements DailyPlanDispatcher
{
    public function dispatch(
        AutoPlanRunId $runId,
        PlanDate $date,
        PlanStrategy $strategy,
        AutoPlanParameters $params,
    ): void {
        GenerateDailyPlanJob::dispatch($runId->toString(), $date->toString(), $params)->afterCommit();
    }
}
