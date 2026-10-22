<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Persistence\Eloquent;

use Logistics\Notifications\Domain\Contract\MessageTypeRuleRepository;
use Logistics\Notifications\Domain\ValueObject\CascadePolicy;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Throwable;

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

        try {
            // Lectura tolerante a canales históricos (PUSH_FCM → FCM; WEB_PUSH/WHATSAPP se descartan).
            return CascadePolicy::fromStored([
                'channels' => $record->channels,
                'escalate_when' => $record->escalate_when,
            ]);
        } catch (Throwable) {
            // Sin ningún canal vigente: se comporta como "sin regla" (cae a la cascada por defecto).
            return null;
        }
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

    public function remove(MessageType $messageType): void
    {
        MessageTypeRuleRecord::query()->whereKey($messageType->value)->delete();
    }

    public function all(): array
    {
        $policies = [];
        foreach (MessageTypeRuleRecord::query()->get() as $record) {
            try {
                $policies[$record->message_type] = CascadePolicy::fromStored([
                    'channels' => $record->channels,
                    'escalate_when' => $record->escalate_when,
                ]);
            } catch (Throwable) {
                // Regla sin canales vigentes: se omite (equivale a no tener regla para ese tipo).
                continue;
            }
        }

        return $policies;
    }
}
