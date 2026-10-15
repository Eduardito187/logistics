<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Query\ListIntegrationTokens;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los tokens de integración. Opcionalmente filtra por estado.
 */
final readonly class ListIntegrationTokens implements Query
{
    public function __construct(
        public ?string $status = null,
    ) {}
}
