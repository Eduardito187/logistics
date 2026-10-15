<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\GrantPermissionToRole;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de otorgar un permiso a un rol.
 */
final readonly class GrantPermissionToRole implements Command
{
    public function __construct(
        public string $roleId,
        public string $permissionKey,
    ) {}
}
