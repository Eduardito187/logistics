<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\Factory\ScanEventRecordFactory;

/**
 * Fila de la tabla `execution_scan_events`. Append-only: nunca se modifica ni se borra. PK uuid string.
 *
 * El índice único en idempotency_key respalda la idempotencia offline a nivel de base, además de la
 * verificación en el agregado.
 *
 * @property string $id
 * @property string $delivery_id
 * @property string $type
 * @property string $unit_id
 * @property string $lat
 * @property string $lng
 * @property string $device_id
 * @property string $user_id
 * @property string $occurred_at
 * @property string $idempotency_key
 */
final class ScanEventRecord extends Model
{
    /** @use HasFactory<ScanEventRecordFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'execution_scan_events';

    protected $guarded = [];

    /**
     * Entrega a la que pertenece el escaneo. A través de ella se alcanza el pedido legible
     * (delivery.shipment) y el vehículo, para no mostrar UUIDs crudos en el admin.
     *
     * @return BelongsTo<DeliveryRecord, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(DeliveryRecord::class, 'delivery_id');
    }

    /**
     * Las factories del package viven fuera del namespace que Laravel adivina; se enlazan a mano.
     */
    protected static function newFactory(): ScanEventRecordFactory
    {
        return ScanEventRecordFactory::new();
    }
}
