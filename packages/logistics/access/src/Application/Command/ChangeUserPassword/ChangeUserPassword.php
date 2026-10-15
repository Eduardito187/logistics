<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\ChangeUserPassword;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de cambiar la contraseña de un usuario.
 */
final readonly class ChangeUserPassword implements Command
{
    public function __construct(
        public string $userId,
        public string $newPlainPassword,
    ) {}
}
