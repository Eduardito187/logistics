<?php

declare(strict_types=1);

namespace Commerce\Accounts\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se suspendió una cuenta: queda bloqueada comercialmente (se conserva su histórico).
 */
final readonly class AccountSuspended extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'accounts.account_suspended';
    }

    public function payload(): array
    {
        return [];
    }
}
