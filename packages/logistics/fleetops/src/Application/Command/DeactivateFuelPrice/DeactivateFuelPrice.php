<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\DeactivateFuelPrice;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de desactivar un precio de combustible.
 */
final readonly class DeactivateFuelPrice implements Command
{
    public function __construct(
        public string $id,
    ) {}
}
