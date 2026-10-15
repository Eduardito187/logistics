<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\DeleteRole;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de eliminar un rol del catálogo de RBAC.
 */
final readonly class DeleteRole implements Command
{
    public function __construct(
        public string $roleId,
    ) {}
}
