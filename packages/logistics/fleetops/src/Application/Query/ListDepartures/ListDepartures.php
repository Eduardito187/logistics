<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\ListDepartures;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista salidas con filtros opcionales: por vehículo, estado ('out'|'returned') y rango de fecha de
 * salida (ISO 8601).
 */
final readonly class ListDepartures implements Query
{
    public function __construct(
        public ?string $vehicleId = null,
        public ?string $status = null,
        public ?string $from = null,
        public ?string $to = null,
    ) {}
}
