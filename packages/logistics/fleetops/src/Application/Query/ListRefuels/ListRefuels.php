<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\ListRefuels;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista las cargas de combustible. Filtros opcionales por vehículo y rango de fechas (ISO 8601).
 */
final readonly class ListRefuels implements Query
{
    public function __construct(
        public ?string $vehicleId = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?int $limit = null,
        public ?int $offset = null,
    ) {}
}
