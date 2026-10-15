<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Contract;

use Logistics\Planning\Domain\ValueObject\AutoPlanParameters;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;

/**
 * Puerto que resuelve los parámetros CONFIGURABLES del auto-planificador para una corrida: toma los
 * valores por defecto de la configuración versionada (settings) y les aplica el override puntual que
 * venga por comando/API. El dominio define QUÉ necesita; la implementación (settings/config) vive en
 * Infrastructure.
 */
interface AutoPlanSettingsProvider
{
    /**
     * @param array<string, mixed> $overrides overrides puntuales (p. ej. {"depart_at": "...", "capacity_slack": 0.15})
     */
    public function parametersFor(PlanDate $date, PlanStrategy $strategy, array $overrides): AutoPlanParameters;
}
