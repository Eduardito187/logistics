<?php

declare(strict_types=1);

namespace Commerce\Search\Application\Query\SearchProducts;

use Commerce\Search\Domain\ValueObject\SearchCriteria;
use Logistics\Shared\Application\Query\Query;

/**
 * Busca productos del catálogo por texto libre (FTS) y/o filtros, paginando por cursor (keyset).
 *
 * Transporta primitivos; el handler los valida construyendo el VO de dominio `SearchCriteria`.
 */
final readonly class SearchProducts implements Query
{
    public function __construct(
        public ?string $term = null,
        public ?string $categoryId = null,
        public string $status = SearchCriteria::DEFAULT_STATUS,
        public int $limit = SearchCriteria::DEFAULT_LIMIT,
        public ?string $cursor = null,
    ) {}
}
