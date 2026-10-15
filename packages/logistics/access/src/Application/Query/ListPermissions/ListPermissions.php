<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Query\ListPermissions;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los permisos del catálogo. Opcionalmente filtra por grupo.
 */
final readonly class ListPermissions implements Query
{
    public function __construct(
        public ?string $group = null,
    ) {}
}
