<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Logistics\Notifications\Database\Factories\MessageTemplateRecordFactory;

/**
 * Fila de la tabla `notification_message_templates`. PK uuid string no incremental (UUID v7). Única por
 * (message_type, channel, locale). Es detalle de Infrastructure: el mapeo al agregado lo hace el mapper.
 *
 * @property string $id
 * @property string $message_type
 * @property string $channel
 * @property string $locale
 * @property string $subject
 * @property string $body
 */
final class MessageTemplateRecord extends Model
{
    /** @use HasFactory<MessageTemplateRecordFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'notification_message_templates';

    protected $guarded = [];

    protected static function newFactory(): MessageTemplateRecordFactory
    {
        return MessageTemplateRecordFactory::new();
    }
}
