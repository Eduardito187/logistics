<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Query\GetPlan;

use Logistics\Shared\Application\Query\Query;

/**
 * Busca un plan por su id. Devuelve un PlanView o null si no existe.
 */
final readonly class GetPlan implements Query
{
    public function __construct(
        public string $planId,
    ) {}
}
