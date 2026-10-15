<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\DeactivatePlan;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de desactivar un plan de mantenimiento (deja de vigilar vencimientos).
 */
final readonly class DeactivatePlan implements Command
{
    public function __construct(
        public string $planId,
    ) {}
}
