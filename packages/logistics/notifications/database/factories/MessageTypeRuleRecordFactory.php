<?php

declare(strict_types=1);

namespace Logistics\Notifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\EscalateCondition;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\MessageTypeRuleRecord;

/**
 * Factory de reglas de cascada por tipo de mensaje para tests y seeders.
 *
 * @extends Factory<MessageTypeRuleRecord>
 */
final class MessageTypeRuleRecordFactory extends Factory
{
    protected $model = MessageTypeRuleRecord::class;

    public function definition(): array
    {
        return [
            'message_type' => MessageType::ConfirmLocation->value,
            'channels' => [
                Channel::PushNtfy->value,
                Channel::WebPush->value,
                Channel::Email->value,
                Channel::Sms->value,
            ],
            'escalate_when' => EscalateCondition::NoOpen->value,
        ];
    }
}
