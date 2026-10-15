<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Query\GetUser;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide un usuario por su identificador.
 */
final readonly class GetUser implements Query
{
    public function __construct(
        public string $userId,
    ) {}
}
