<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\ListCrews;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista las tripulaciones (chofer + ayudantes + vehículo por fecha) con filtros opcionales, paginada.
 */
final readonly class ListCrews implements Query
{
    public function __construct(
        public ?string $date = null,
        public ?string $vehicleId = null,
        public ?string $driverId = null,
        public int $limit = 50,
        public int $offset = 0,
    ) {}
}
