<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Query\GetSettlement;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide una liquidación concreta por su id.
 */
final readonly class GetSettlement implements Query
{
    public function __construct(
        public string $settlementId,
    ) {}
}
