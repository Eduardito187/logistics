<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Query\GetIntegrationToken;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide un token de integración por su id.
 */
final readonly class GetIntegrationToken implements Query
{
    public function __construct(
        public string $tokenId,
    ) {}
}
