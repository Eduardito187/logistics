<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se suspendió a un usuario.
 */
final readonly class UserSuspended extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'access.user_suspended';
    }

    /**
     * @return array<string, never>
     */
    public function payload(): array
    {
        return [];
    }
}
