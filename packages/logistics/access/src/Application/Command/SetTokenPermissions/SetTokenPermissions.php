<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\SetTokenPermissions;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de reemplazar el conjunto completo de permisos de un token de integración.
 */
final readonly class SetTokenPermissions implements Command
{
    /**
     * @param list<string> $permissionKeys
     */
    public function __construct(
        public string $tokenId,
        public array $permissionKeys,
    ) {}
}
