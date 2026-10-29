<?php

declare(strict_types=1);

namespace Commerce\Pricing\Application\Query\ListPrices;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los precios (ítems) de una lista de precios.
 */
final readonly class ListPrices implements Query
{
    public function __construct(
        public string $priceListId,
    ) {}
}
