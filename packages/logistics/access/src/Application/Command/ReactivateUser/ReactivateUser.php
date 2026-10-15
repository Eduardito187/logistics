<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\ReactivateUser;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de reactivar a un usuario.
 */
final readonly class ReactivateUser implements Command
{
    public function __construct(
        public string $userId,
    ) {}
}
