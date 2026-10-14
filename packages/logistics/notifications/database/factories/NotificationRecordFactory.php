<?php

declare(strict_types=1);

namespace Logistics\Notifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\EscalateCondition;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Domain\ValueObject\NotificationStatus;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\NotificationRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Factory de notificaciones para tests y seeders. Genera una notificación encolada con la cascada
 * completa del ADR 006 y un envío aleatorio.
 *
 * @extends Factory<NotificationRecord>
 */
final class NotificationRecordFactory extends Factory
{
    protected $model = NotificationRecord::class;

    public function definition(): array
    {
        $cascade = [Channel::PushNtfy->value, Channel::WebPush->value, Channel::Email->value, Channel::Sms->value];

        return [
            'id' => Uuid::generate()->value,
            'shipment_id' => Uuid::generate()->value,
            'message_type' => $this->faker->randomElement(array_map(fn (MessageType $t): string => $t->value, MessageType::cases())),
            'status' => NotificationStatus::Queued->value,
            'current_channel' => Channel::PushNtfy->value,
            'policy' => [
                'channels' => $cascade,
                'escalate_when' => EscalateCondition::NoOpen->value,
            ],
            'attempts' => [],
            'email' => $this->faker->optional()->safeEmail(),
            'phone' => $this->faker->optional()->numerify('+5917#######'),
        ];
    }

    /**
     * Estado: notificación ya enviada por push.
     */
    public function sent(): self
    {
        return $this->state(fn (): array => [
            'status' => NotificationStatus::Sent->value,
            'current_channel' => Channel::PushNtfy->value,
            'attempts' => [Channel::PushNtfy->value => 1],
        ]);
    }
}
