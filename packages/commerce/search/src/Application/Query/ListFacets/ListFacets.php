<?php

declare(strict_types=1);

namespace Commerce\Search\Application\Query\ListFacets;

use Commerce\Search\Domain\ValueObject\SearchCriteria;
use Logistics\Shared\Application\Query\Query;

/**
 * Cuenta productos por categoría (facetas de navegación) para un estado de publicación dado.
 */
final readonly class ListFacets implements Query
{
    public function __construct(
        public string $status = SearchCriteria::DEFAULT_STATUS,
    ) {}
}
