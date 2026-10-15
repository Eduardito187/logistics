<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\ReadModel;

use Logistics\Notifications\Domain\ValueObject\CascadePolicy;

/**
 * Modelo de lectura de la regla de cascada de un tipo de mensaje: canales en orden, condición de
 * escalado y si la regla es personalizada o el default del ADR 006.
 */
final readonly class MessageTypeRuleView
{
    /**
     * @param list<string> $channels
     */
    public function __construct(
        public string $messageType,
        public array $channels,
        public string $escalateWhen,
        public bool $customized,
    ) {}

    public static function fromPolicy(string $messageType, CascadePolicy $policy, bool $customized): self
    {
        $primitive = $policy->toArray();

        return new self(
            $messageType,
            $primitive['channels'],
            $primitive['escalate_when'],
            $customized,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'message_type' => $this->messageType,
            'channels' => $this->channels,
            'escalate_when' => $this->escalateWhen,
            'customized' => $this->customized,
        ];
    }
}
