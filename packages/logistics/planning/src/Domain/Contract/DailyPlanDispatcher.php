<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Contract;

use Logistics\Planning\Domain\ValueObject\AutoPlanParameters;
use Logistics\Planning\Domain\ValueObject\AutoPlanRunId;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;

/**
 * Puerto que ENCOLA el cálculo pesado del auto-planificador para que corra ASÍNCRONO (fuera del request
 * del usuario). El handler del comando solo deja la corrida encolada y delega aquí el disparo del
 * trabajo; el dominio no conoce colas ni Laravel. La implementación (cola Valkey) vive en
 * Infrastructure y dispara el job después del commit, nunca dentro de la transacción del comando.
 */
interface DailyPlanDispatcher
{
    public function dispatch(
        AutoPlanRunId $runId,
        PlanDate $date,
        PlanStrategy $strategy,
        AutoPlanParameters $params,
    ): void;
}
