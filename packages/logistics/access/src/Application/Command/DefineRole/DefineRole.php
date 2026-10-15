<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\DefineRole;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de definir un rol nuevo con su conjunto inicial de permisos.
 */
final readonly class DefineRole implements Command
{
    /**
     * @param list<string> $permissionKeys
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?string $description = null,
        public array $permissionKeys = [],
        public bool $isSystem = false,
    ) {}
}
