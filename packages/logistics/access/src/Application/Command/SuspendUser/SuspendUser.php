<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\SuspendUser;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de suspender a un usuario.
 */
final readonly class SuspendUser implements Command
{
    public function __construct(
        public string $userId,
    ) {}
}
