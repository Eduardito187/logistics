<?php

declare(strict_types=1);

namespace Logistics\Settlement\Application\Query\ListSettlements;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista liquidaciones de sellers, con filtros opcionales por seller, estado y periodo (rango de fechas).
 */
final readonly class ListSettlements implements Query
{
    public function __construct(
        public ?string $sellerId = null,
        public ?string $status = null,
        public ?string $periodFrom = null,
        public ?string $periodTo = null,
    ) {}
}
