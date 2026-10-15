<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Cambió el conjunto de permisos concedidos a un token de integración.
 */
final readonly class IntegrationTokenPermissionsChanged extends BaseDomainEvent
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
        return 'access.integration_token_permissions_changed';
    }

    /**
     * @return array{permission_keys: list<string>}
     */
    public function payload(): array
    {
        return [
            'permission_keys' => $this->permissionKeys,
        ];
    }
}
