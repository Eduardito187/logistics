<?php

declare(strict_types=1);

namespace Commerce\Promotions\Application\Query\ListPromotions;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista promociones, opcionalmente filtrando por estado (draft|active|expired).
 */
final readonly class ListPromotions implements Query
{
    public function __construct(
        public ?string $status = null,
    ) {}
}
