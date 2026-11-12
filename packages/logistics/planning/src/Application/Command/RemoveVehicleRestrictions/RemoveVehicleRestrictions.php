<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\RemoveVehicleRestrictions;

use Logistics\Shared\Application\Command\Command;

/**
 * Quita las restricciones de ruteo de un camión: vuelve a aceptar cualquier carga y zona.
 */
final readonly class RemoveVehicleRestrictions implements Command
{
    public function __construct(
        public string $vehicleId,
    ) {}
}
