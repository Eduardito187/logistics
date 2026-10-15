<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\ActivatePlan;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de activar un plan de mantenimiento (vuelve a vigilar vencimientos).
 */
final readonly class ActivatePlan implements Command
{
    public function __construct(
        public string $planId,
    ) {}
}
