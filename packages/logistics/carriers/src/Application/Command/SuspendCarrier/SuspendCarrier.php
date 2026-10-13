<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\SuspendCarrier;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de suspender a un transportista para que deje de recibir asignaciones.
 */
final readonly class SuspendCarrier implements Command
{
    public function __construct(
        public string $carrierId,
    ) {}
}
