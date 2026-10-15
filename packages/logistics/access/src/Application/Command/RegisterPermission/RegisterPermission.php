<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\RegisterPermission;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de registrar un permiso nuevo en el catálogo de RBAC.
 */
final readonly class RegisterPermission implements Command
{
    public function __construct(
        public string $key,
        public ?string $description = null,
        public bool $isSystem = false,
    ) {}
}
