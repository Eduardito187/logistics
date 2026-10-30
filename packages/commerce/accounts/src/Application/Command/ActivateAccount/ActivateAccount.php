<?php

declare(strict_types=1);

namespace Commerce\Accounts\Application\Command\ActivateAccount;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de reactivar una cuenta suspendida.
 */
final readonly class ActivateAccount implements Command
{
    public function __construct(
        public string $accountId,
    ) {}
}
