<?php

declare(strict_types=1);

namespace Logistics\Returns\Application\Query\ListReturns;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista devoluciones filtrando por seller dueño y/o estado (cualquiera nullable = sin filtro).
 */
final readonly class ListReturns implements Query
{
    public function __construct(
        public ?string $sellerId = null,
        public ?string $status = null,
    ) {}
}
