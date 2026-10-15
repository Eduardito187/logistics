<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se definió un rol nuevo con su conjunto inicial de permisos.
 */
final readonly class RoleDefined extends BaseDomainEvent
{
    /**
     * @param list<string> $permissionKeys
     */
    public function __construct(
        string $aggregateId,
        public string $key,
        public string $name,
        public array $permissionKeys,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'access.role_defined';
    }

    public function payload(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'permission_keys' => $this->permissionKeys,
        ];
    }
}
