<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se registró un usuario nuevo.
 */
final readonly class UserRegistered extends BaseDomainEvent
{
    /**
     * @param list<string> $roleKeys
     */
    public function __construct(
        string $aggregateId,
        public string $name,
        public string $email,
        public array $roleKeys,
        public string $status,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'access.user_registered';
    }

    /**
     * @return array{name: string, email: string, role_keys: list<string>, status: string}
     */
    public function payload(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'role_keys' => $this->roleKeys,
            'status' => $this->status,
        ];
    }
}
