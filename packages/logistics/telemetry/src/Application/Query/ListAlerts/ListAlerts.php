<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\ListAlerts;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista alertas con filtros opcionales por vehículo, por tipo y por estado de reconocimiento.
 */
final readonly class ListAlerts implements Query
{
    public function __construct(
        public ?string $vehicleId = null,
        public ?string $type = null,
        public ?bool $acknowledged = null,
    ) {}
}
