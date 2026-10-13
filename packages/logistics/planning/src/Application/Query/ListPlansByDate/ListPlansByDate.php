<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Query\ListPlansByDate;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los planes de reparto de un día (formato 'Y-m-d'). Devuelve una lista de PlanView.
 */
final readonly class ListPlansByDate implements Query
{
    public function __construct(
        public string $planDate,
    ) {}
}
