<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\ListOdometerReadings;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista lecturas de odómetro con filtros opcionales: por vehículo y rango de fecha de lectura (ISO 8601).
 */
final readonly class ListOdometerReadings implements Query
{
    public function __construct(
        public ?string $vehicleId = null,
        public ?string $from = null,
        public ?string $to = null,
    ) {}
}
