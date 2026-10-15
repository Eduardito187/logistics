<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se cambió la contraseña de un usuario. El hash nunca viaja en el evento.
 */
final readonly class UserPasswordChanged extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'access.user_password_changed';
    }

    /**
     * @return array<string, never>
     */
    public function payload(): array
    {
        return [];
    }
}
