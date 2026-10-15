<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Logistics\Notifications\Database\Factories\NotificationRecordFactory;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

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
 * @property string|null         $email
 * @property string|null         $phone
 * @property string|null         $telegram_chat_id
 */
final class NotificationRecord extends Model
{
    /** @use HasFactory<NotificationRecordFactory> */
    use HasFactory;

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

    /**
     * Factory del modelo (namespace del package, no el default de la app).
     */
    protected static function newFactory(): NotificationRecordFactory
    {
        return NotificationRecordFactory::new();
    }

    /**
     * Envío al que pertenece la notificación. Permite mostrar datos humanos (referencia externa y
     * cliente) en el admin en vez del UUID crudo de `shipment_id`, y eager-load para evitar N+1.
     *
     * @return BelongsTo<ShipmentRecord, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(ShipmentRecord::class, 'shipment_id');
    }
}
