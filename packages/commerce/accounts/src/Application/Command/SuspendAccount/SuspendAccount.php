<?php

declare(strict_types=1);

namespace Commerce\Accounts\Application\Command\SuspendAccount;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de suspender una cuenta (bloquearla comercialmente, conservando su histórico).
 */
final readonly class SuspendAccount implements Command
{
    public function __construct(
        public string $accountId,
    ) {}
}
