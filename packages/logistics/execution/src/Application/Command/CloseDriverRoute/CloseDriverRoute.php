<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\CloseDriverRoute;

use Logistics\Shared\Application\Command\Command;

/**
 * El chofer CIERRA la ruta del día (POST /app/vehicles/{id}/route/close). No exige que todas las paradas
 * estén entregadas: las pendientes quedan como están. Idempotente: la primera llamada gana.
 */
final readonly class CloseDriverRoute implements Command
{
    public function __construct(
        public string $vehicleId,
        public string $occurredAt,
        public ?string $routeDate = null,
        public ?float $odometerKm = null,
        public ?float $lat = null,
        public ?float $lng = null,
    ) {}
}
