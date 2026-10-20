<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\ListExpenses;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista gastos de flota, filtrando opcionalmente por vehículo, categoría y rango de fechas (ISO 8601).
 */
final readonly class ListExpenses implements Query
{
    public function __construct(
        public ?string $vehicleId = null,
        public ?string $category = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?int $limit = null,
        public ?int $offset = null,
    ) {}
}
