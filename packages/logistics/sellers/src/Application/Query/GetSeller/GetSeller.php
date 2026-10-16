<?php

declare(strict_types=1);

namespace Logistics\Sellers\Application\Query\GetSeller;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide un seller por su id.
 */
final readonly class GetSeller implements Query
{
    public function __construct(
        public string $sellerId,
    ) {}
}
