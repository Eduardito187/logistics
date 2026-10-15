<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\AssignRoleToUser;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de asignar un rol (por clave) a un usuario.
 */
final readonly class AssignRoleToUser implements Command
{
    public function __construct(
        public string $userId,
        public string $roleKey,
    ) {}
}
