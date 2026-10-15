<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\ActivateFuelPrice;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de activar un precio de combustible.
 */
final readonly class ActivateFuelPrice implements Command
{
    public function __construct(
        public string $id,
    ) {}
}
