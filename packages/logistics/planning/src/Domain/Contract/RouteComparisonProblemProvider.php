<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Contract;

use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Planning\Domain\ValueObject\RouteComparisonProblem;

/**
 * Puerta que reconstruye el problema de ruteo de un plan (origen + candidatos + camión) para comparar los
 * backends de ruteo (R-06). La implementación (Infrastructure) resuelve el CD de origen y lee, read-only y
 * en lote, las coordenadas y la carga de los envíos del plan y la capacidad/perfil del camión.
 *
 * Devuelve null si el plan no existe o no se pueden reconstruir sus datos (p. ej. sin coordenadas de
 * envíos): en ese caso no hay nada que comparar.
 */
interface RouteComparisonProblemProvider
{
    public function forPlan(PlanId $planId): ?RouteComparisonProblem;
}
