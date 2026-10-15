<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se registró un permiso nuevo en el catálogo de RBAC.
 */
final readonly class PermissionRegistered extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $key,
        public string $group,
        public string $action,
        public bool $isSystem,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'access.permission_registered';
    }

    public function payload(): array
    {
        return [
            'key' => $this->key,
            'group' => $this->group,
            'action' => $this->action,
            'is_system' => $this->isSystem,
        ];
    }
}
