<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\ListTrips;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista trips. Opcionalmente filtra por vehículo y por día (fecha YYYY-MM-DD).
 */
final readonly class ListTrips implements Query
{
    public function __construct(
        public ?string $vehicleId = null,
        public ?string $date = null,
        public ?int $limit = null,
        public ?int $offset = null,
    ) {}
}
