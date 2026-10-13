<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se reactivó a un usuario.
 */
final readonly class UserReactivated extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'access.user_reactivated';
    }

    /**
     * @return array<string, never>
     */
    public function payload(): array
    {
        return [];
    }
}
