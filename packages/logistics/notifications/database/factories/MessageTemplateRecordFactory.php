<?php

declare(strict_types=1);

namespace Logistics\Notifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Notifications\Domain\ValueObject\Channel;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Infrastructure\Persistence\Eloquent\MessageTemplateRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Factory de plantillas de mensaje para tests y seeders.
 *
 * @extends Factory<MessageTemplateRecord>
 */
final class MessageTemplateRecordFactory extends Factory
{
    protected $model = MessageTemplateRecord::class;

    public function definition(): array
    {
        return [
            'id' => Uuid::generate()->value,
            'message_type' => MessageType::EnRoute->value,
            'channel' => Channel::Fcm->value,
            'locale' => 'es',
            'subject' => 'Tu pedido va en camino',
            'body' => 'Tu pedido {{shipment_id}} salió en ruta hacia tu dirección.',
        ];
    }
}
