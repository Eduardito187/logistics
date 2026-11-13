<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\Contract;

use Logistics\Compensation\Domain\ValueObject\CompensationPolicy;

/**
 * Fuente de la política de compensación vigente (y su versión). La implementación la lee de la
 * configuración versionada de `logistics/settings` y cae a la config del package como fallback.
 */
interface CompensationPolicyProvider
{
    public function current(): CompensationPolicy;

    /**
     * Versión de la política vigente (se persiste en el caso para auditoría y reproducibilidad del cálculo).
     */
    public function version(): int;
}
