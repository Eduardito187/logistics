<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\RenameRole;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de renombrar un rol (nombre y/o descripción visibles).
 */
final readonly class RenameRole implements Command
{
    public function __construct(
        public string $roleId,
        public string $name,
        public ?string $description = null,
    ) {}
}
