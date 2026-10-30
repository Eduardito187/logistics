<?php

declare(strict_types=1);

namespace Commerce\Accounts\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se reactivó una cuenta suspendida: vuelve a poder operar.
 */
final readonly class AccountActivated extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'accounts.account_activated';
    }

    public function payload(): array
    {
        return [];
    }
}
