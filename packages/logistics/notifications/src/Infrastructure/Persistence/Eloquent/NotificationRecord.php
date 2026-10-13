<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de la tabla `notifications`. PK uuid string no incremental (UUID v7 generado por el dominio).
 *
 * Es detalle de Infrastructure: nunca cruza hacia Application ni Domain. El mapeo a/desde el agregado
 * lo hace NotificationMapper. La política y los intentos se guardan como jsonb (forma primitiva).
 *
 * @property string              $id
 * @property string              $shipment_id
 * @property string              $message_type
 * @property string              $status
 * @property string              $current_channel
 * @property array<string,mixed> $policy
 * @property array<string,int>   $attempts
 */
final class NotificationRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'notifications';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'policy' => 'array',
            'attempts' => 'array',
        ];
    }
}
