<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\RevokePermissionFromRole;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de revocar un permiso de un rol.
 */
final readonly class RevokePermissionFromRole implements Command
{
    public function __construct(
        public string $roleId,
        public string $permissionKey,
    ) {}
}
