<?php

declare(strict_types=1);

namespace Commerce\Accounts\Application\Command\RegisterAccount;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de registrar una cuenta comercial para un usuario de `access` (referenciado por UUID).
 */
final readonly class RegisterAccount implements Command
{
    public function __construct(
        public string $type,
        public string $userId,
        public string $displayName,
        public ?string $taxId = null,
    ) {}
}
