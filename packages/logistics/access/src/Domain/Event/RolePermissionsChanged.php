<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Cambió el conjunto de permisos de un rol (otorgar, revocar o reemplazo completo).
 */
final readonly class RolePermissionsChanged extends BaseDomainEvent
{
    /**
     * @param list<string> $permissionKeys
     */
    public function __construct(
        string $aggregateId,
        public array $permissionKeys,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'access.role_permissions_changed';
    }

    public function payload(): array
    {
        return [
            'permission_keys' => $this->permissionKeys,
        ];
    }
}
