<?php

declare(strict_types=1);

namespace Logistics\Sellers\Application\Query\ListSellerSkus;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los SKUs del catálogo de un seller.
 */
final readonly class ListSellerSkus implements Query
{
    public function __construct(
        public string $sellerId,
    ) {}
}
