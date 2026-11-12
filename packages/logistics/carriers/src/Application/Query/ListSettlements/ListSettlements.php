<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Query\ListSettlements;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista las liquidaciones, con filtros opcionales por transportista, por estado y por rango de período.
 *
 * `periodFrom` / `periodTo` llegan como fechas `Y-m-d` (inclusivas, ya validadas en Presentation). Como el
 * período de una liquidación es MENSUAL (`YYYY-MM`), entra toda liquidación cuyo mes se solapa con el
 * rango: desde el mes de `periodFrom` hasta el mes de `periodTo`, ambos incluidos.
 */
final readonly class ListSettlements implements Query
{
    public function __construct(
        public ?string $carrierId = null,
        public ?string $status = null,
        public ?int $limit = null,
        public ?int $offset = null,
        public ?string $periodFrom = null,
        public ?string $periodTo = null,
    ) {}
}
