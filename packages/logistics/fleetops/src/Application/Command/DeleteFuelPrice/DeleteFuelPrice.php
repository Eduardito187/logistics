<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\DeleteFuelPrice;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de eliminar un precio de combustible del registro.
 */
final readonly class DeleteFuelPrice implements Command
{
    public function __construct(
        public string $id,
    ) {}
}
