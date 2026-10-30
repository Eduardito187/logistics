<?php

declare(strict_types=1);

namespace Commerce\Pricing\Application\Query\ListPriceLists;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista las listas de precios. Opcionalmente filtra por canal (retail|b2b|c2c).
 */
final readonly class ListPriceLists implements Query
{
    public function __construct(
        public ?string $channel = null,
    ) {}
}
