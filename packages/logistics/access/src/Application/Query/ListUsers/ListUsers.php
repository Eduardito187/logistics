<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Query\ListUsers;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los usuarios. Opcionalmente filtra por estado.
 */
final readonly class ListUsers implements Query
{
    public function __construct(
        public ?string $status = null,
    ) {}
}
