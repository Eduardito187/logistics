<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Query\GetAsn;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide un ASN por su id.
 */
final readonly class GetAsn implements Query
{
    public function __construct(
        public string $asnId,
    ) {}
}
