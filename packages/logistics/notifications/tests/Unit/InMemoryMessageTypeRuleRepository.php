<?php

declare(strict_types=1);

namespace Logistics\Notifications\Tests\Unit;

use Logistics\Notifications\Domain\Contract\MessageTypeRuleRepository;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * Proyección de solo-estado en memoria (la tabla propia del listado del admin).
 */
final class InMemoryMessageTypeRuleRepository implements MessageTypeRuleRepository
{
    /** @var array<string, CascadePolicy> */
    public array $policies = [];

    public function policyFor(MessageType $messageType): ?CascadePolicy
    {
        return $this->policies[$messageType->value] ?? null;
    }

    public function save(MessageType $messageType, CascadePolicy $policy): void
    {
        $this->policies[$messageType->value] = $policy;
    }

    public function remove(MessageType $messageType): void
    {
        unset($this->policies[$messageType->value]);
    }

    public function all(): array
    {
        return $this->policies;
    }
}
