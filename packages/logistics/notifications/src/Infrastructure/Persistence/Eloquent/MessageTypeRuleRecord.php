<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Logistics\Notifications\Database\Factories\MessageTypeRuleRecordFactory;

/**
 * Fila de la tabla `notification_message_type_rules`: la cascada personalizada de un tipo de mensaje.
 * La PK es el propio tipo de mensaje (único por tipo). Los canales se guardan como jsonb en orden.
 *
 * @property string       $message_type
 * @property list<string> $channels
 * @property string       $escalate_when
 */
final class MessageTypeRuleRecord extends Model
{
    /** @use HasFactory<MessageTypeRuleRecordFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'message_type';

    protected $table = 'notification_message_type_rules';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channels' => 'array',
        ];
    }

    protected static function newFactory(): MessageTypeRuleRecordFactory
    {
        return MessageTypeRuleRecordFactory::new();
    }
}
