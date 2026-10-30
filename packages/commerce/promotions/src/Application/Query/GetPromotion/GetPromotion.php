<?php

declare(strict_types=1);

namespace Commerce\Promotions\Application\Query\GetPromotion;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta una promoción por su id.
 */
final readonly class GetPromotion implements Query
{
    public function __construct(
        public string $promotionId,
    ) {}
}
