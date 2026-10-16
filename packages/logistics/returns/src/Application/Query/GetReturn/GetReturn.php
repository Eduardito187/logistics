<?php

declare(strict_types=1);

namespace Logistics\Returns\Application\Query\GetReturn;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide una devolución por su id.
 */
final readonly class GetReturn implements Query
{
    public function __construct(
        public string $returnId,
    ) {}
}
