<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Command\BindTripToRoute;

use Logistics\Shared\Application\Command\Command;

/**
 * Liga el trip ACTIVO de un vehículo a la ruta de ejecución del día (Fase 3.3), para que la traza de
 * telemetría quede asociada a esa ruta. Referencia al vehículo y a la ruta solo por id string.
 */
final readonly class BindTripToRoute implements Command
{
    public function __construct(
        public string $vehicleId,
        public string $routeId,
    ) {}
}
