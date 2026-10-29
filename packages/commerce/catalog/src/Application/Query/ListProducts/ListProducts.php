<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\Query\ListProducts;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista productos del catálogo, opcionalmente filtrando por estado (draft|published|archived).
 */
final readonly class ListProducts implements Query
{
    public function __construct(
        public ?string $status = null,
    ) {}
}
