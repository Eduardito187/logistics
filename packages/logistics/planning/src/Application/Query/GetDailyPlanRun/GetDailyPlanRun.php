<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Query\GetDailyPlanRun;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta el estado/resultado de la última corrida del auto-planificador para un día y un modo.
 */
final readonly class GetDailyPlanRun implements Query
{
    public function __construct(
        public string $planDate,
        public string $strategy,
    ) {}
}
