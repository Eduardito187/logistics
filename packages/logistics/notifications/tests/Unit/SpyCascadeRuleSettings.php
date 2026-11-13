<?php

declare(strict_types=1);

namespace Logistics\Notifications\Tests\Unit;

use Logistics\Notifications\Application\Contract\CascadeRuleSettings;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * Doble de las reglas de cascada (puerto CascadeRuleSettings) que registra lo que se le pide guardar o
 * quitar, para verificar que los handlers construyen los VO correctos y delegan en Configuración.
 */
final class SpyCascadeRuleSettings implements CascadeRuleSettings
{
    /** @var list<array{type: MessageType, policy: CascadePolicy, by: string|null}> */
    public array $saved = [];

    /** @var list<array{type: MessageType, by: string|null}> */
    public array $removed = [];

    public function configured(): array
    {
        return [];
    }

    public function policyFor(MessageType $messageType): ?CascadePolicy
    {
        return null;
    }

    public function save(MessageType $messageType, CascadePolicy $policy, ?string $createdBy = null): void
    {
        $this->saved[] = ['type' => $messageType, 'policy' => $policy, 'by' => $createdBy];
    }

    public function remove(MessageType $messageType, ?string $createdBy = null): void
    {
        $this->removed[] = ['type' => $messageType, 'by' => $createdBy];
    }

    public function history(): array
    {
        return [];
    }
}
