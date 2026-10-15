<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Contract;

use Logistics\Fleet\Domain\ValueObject\ShiftRules;

/**
 * Puerto que entrega las reglas de turnos y descansos vigentes. La implementación (Infrastructure) las
 * lee de la configuración versionada (settings) con respaldo por config, igual que DocumentExpiryPolicy.
 */
interface ShiftRulesPolicy
{
    public function rules(): ShiftRules;
}
