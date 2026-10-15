<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se asignó un rol a un usuario.
 */
final readonly class UserRoleAssigned extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $roleKey,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'access.user_role_assigned';
    }

    /**
     * @return array{role_key: string}
     */
    public function payload(): array
    {
        return [
            'role_key' => $this->roleKey,
        ];
    }
}
