<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Query\GetRole;

use Logistics\Shared\Application\Query\Query;

/**
 * Obtiene un rol por su identidad.
 */
final readonly class GetRole implements Query
{
    public function __construct(
        public string $roleId,
    ) {}
}
