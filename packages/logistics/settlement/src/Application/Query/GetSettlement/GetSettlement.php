<?php

declare(strict_types=1);

namespace Logistics\Settlement\Application\Query\GetSettlement;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta una liquidación de seller por su id.
 */
final readonly class GetSettlement implements Query
{
    public function __construct(
        public string $settlementId,
    ) {}
}
