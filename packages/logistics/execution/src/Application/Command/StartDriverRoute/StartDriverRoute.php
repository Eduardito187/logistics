<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\StartDriverRoute;

use Logistics\Shared\Application\Command\Command;

/**
 * El chofer INICIA la ruta del día del camión (POST /app/vehicles/{id}/route/start). Idempotente: la
 * primera llamada gana; los reintentos no cambian nada ni reemiten eventos.
 */
final readonly class StartDriverRoute implements Command
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
