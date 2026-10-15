<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\ReactivateCarrier;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de reactivar a un transportista suspendido para que vuelva a recibir asignaciones.
 */
final readonly class ReactivateCarrier implements Command
{
    public function __construct(
        public string $carrierId,
    ) {}
}
