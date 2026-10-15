<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\SetRolePermissions;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de reemplazar el conjunto completo de permisos de un rol (usado por el admin).
 */
final readonly class SetRolePermissions implements Command
{
    /**
     * @param list<string> $permissionKeys
     */
    public function __construct(
        public string $roleId,
        public array $permissionKeys,
    ) {}
}
