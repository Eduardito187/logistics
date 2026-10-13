<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se renombró un rol (nombre y/o descripción visibles).
 */
final readonly class RoleRenamed extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $name,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'access.role_renamed';
    }

    public function payload(): array
    {
        return [
            'name' => $this->name,
        ];
    }
}
