<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se eliminó un rol del catálogo de RBAC.
 */
final readonly class RoleDeleted extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $key,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'access.role_deleted';
    }

    public function payload(): array
    {
        return [
            'key' => $this->key,
        ];
    }
}
