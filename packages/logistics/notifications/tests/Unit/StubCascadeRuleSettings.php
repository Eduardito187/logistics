<?php

declare(strict_types=1);

namespace Logistics\Notifications\Tests\Unit;

use Logistics\Notifications\Application\Contract\CascadeRuleSettings;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * Reglas de cascada configuradas en memoria: devuelve un override solo para los tipos que se le cargaron.
 */
final class StubCascadeRuleSettings implements CascadeRuleSettings
{
    /**
     * @param array<string, CascadePolicy> $overrides
     */
    public function __construct(private array $overrides = []) {}

    public function configured(): array
    {
        return $this->overrides;
    }

    public function policyFor(MessageType $messageType): ?CascadePolicy
    {
        return $this->overrides[$messageType->value] ?? null;
    }

    public function save(MessageType $messageType, CascadePolicy $policy, ?string $createdBy = null): void {}

    public function remove(MessageType $messageType, ?string $createdBy = null): void {}

    public function history(): array
    {
        return [];
    }
}
