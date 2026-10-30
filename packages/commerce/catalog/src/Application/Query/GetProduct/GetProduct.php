<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\Query\GetProduct;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta un producto por su id.
 */
final readonly class GetProduct implements Query
{
    public function __construct(
        public string $productId,
    ) {}
}
