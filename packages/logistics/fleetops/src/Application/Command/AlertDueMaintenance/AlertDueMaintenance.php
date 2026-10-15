<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\AlertDueMaintenance;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de barrer los mantenimientos por vencer/vencidos y, si hay, emitir la alerta al supervisor.
 */
final readonly class AlertDueMaintenance implements Command
{
    public function __construct(
        public ?string $referenceDate = null,
    ) {}
}
