<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Persistence\Eloquent;

use Logistics\Notifications\Domain\Contract\MessageTypeRuleRepository;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * Implementación Eloquent de las reglas de cascada por tipo de mensaje. Guarda la política como la
 * forma primitiva del VO (canales en orden + condición de escalado) en una fila por tipo.
 */
final class EloquentMessageTypeRuleRepository implements MessageTypeRuleRepository
{
    public function policyFor(MessageType $messageType): ?CascadePolicy
    {
        $record = MessageTypeRuleRecord::query()->find($messageType->value);
        if ($record === null) {
            return null;
        }

        return CascadePolicy::fromArray([
            'channels' => $record->channels,
            'escalate_when' => $record->escalate_when,
        ]);
    }

    public function save(MessageType $messageType, CascadePolicy $policy): void
    {
        $primitive = $policy->toArray();

        MessageTypeRuleRecord::query()->updateOrCreate(
            ['message_type' => $messageType->value],
            [
                'channels' => $primitive['channels'],
                'escalate_when' => $primitive['escalate_when'],
            ],
        );
    }

    public function all(): array
    {
        $policies = [];
        foreach (MessageTypeRuleRecord::query()->get() as $record) {
            $policies[$record->message_type] = CascadePolicy::fromArray([
                'channels' => $record->channels,
                'escalate_when' => $record->escalate_when,
            ]);
        }

        return $policies;
    }
}
