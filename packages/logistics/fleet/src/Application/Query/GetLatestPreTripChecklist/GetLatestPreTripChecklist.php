<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\GetLatestPreTripChecklist;

use Logistics\Shared\Application\Query\Query;

/**
 * Último checklist pre-salida de un vehículo (para el recordatorio de la app sin bajar el historial).
 */
final readonly class GetLatestPreTripChecklist implements Query
{
    public function __construct(
        public string $vehicleId,
    ) {}
}
