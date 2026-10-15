<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\SetHelperAvailability;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de activar o dar de baja a un ayudante (disponibilidad para armar tripulación).
 */
final readonly class SetHelperAvailability implements Command
{
    public function __construct(
        public string $helperId,
        public bool $active,
    ) {}
}
