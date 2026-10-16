<?php

declare(strict_types=1);

namespace Logistics\Sellers\Application\Query\ListSellers;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los sellers. Opcionalmente filtra por estado (onboarding|active|suspended).
 */
final readonly class ListSellers implements Query
{
    public function __construct(
        public ?string $status = null,
    ) {}
}
