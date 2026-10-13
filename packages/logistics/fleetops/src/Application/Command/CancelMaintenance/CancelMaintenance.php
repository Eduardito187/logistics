<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\CancelMaintenance;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de cancelar una orden de mantenimiento (no se ejecutará).
 */
final readonly class CancelMaintenance implements Command
{
    public function __construct(
        public string $orderId,
    ) {}
}
