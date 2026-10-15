<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\StartMaintenance;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de iniciar una orden de mantenimiento agendada (el vehículo entra al taller).
 */
final readonly class StartMaintenance implements Command
{
    public function __construct(
        public string $orderId,
    ) {}
}
