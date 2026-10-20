<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Query\ListSettlements;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista las liquidaciones, con filtros opcionales por transportista y por estado.
 */
final readonly class ListSettlements implements Query
{
    public function __construct(
        public ?string $carrierId = null,
        public ?string $status = null,
        public ?int $limit = null,
        public ?int $offset = null,
    ) {}
}
