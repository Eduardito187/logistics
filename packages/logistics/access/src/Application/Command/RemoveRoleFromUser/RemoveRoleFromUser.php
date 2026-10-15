<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\RemoveRoleFromUser;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de quitar un rol (por clave) a un usuario.
 */
final readonly class RemoveRoleFromUser implements Command
{
    public function __construct(
        public string $userId,
        public string $roleKey,
    ) {}
}
