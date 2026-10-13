<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\Factory\DeliveryRecordFactory;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Fila de la tabla `execution_deliveries`. PK uuid string no incremental (UUID v7 del dominio).
 *
 * Es detalle de Infrastructure: nunca cruza hacia Application ni Domain. El mapeo a/desde el agregado
 * lo hace DeliveryMapper.
 *
 * @property string      $id
 * @property string      $shipment_id
 * @property string      $vehicle_id
 * @property string      $status
 * @property string|null $pod_confirmation
 * @property string|null $pod_photo_url
 * @property string|null $pod_captured_at
 * @property string|null $failure_reason
 */
final class DeliveryRecord extends Model
{
    /** @use HasFactory<DeliveryRecordFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'execution_deliveries';

    protected $guarded = [];

    /**
     * @return HasMany<ScanEventRecord, $this>
     */
    public function scans(): HasMany
    {
        return $this->hasMany(ScanEventRecord::class, 'delivery_id');
    }

    /**
     * Pedido (envío) al que corresponde la entrega. Permite mostrar la referencia externa legible
     * del pedido en el admin en vez del UUID. Solo lectura, para columnas y títulos del panel.
     *
     * @return BelongsTo<ShipmentRecord, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(ShipmentRecord::class, 'shipment_id');
    }

    /**
     * Vehículo asignado a la entrega. Permite mostrar la placa legible en vez del UUID.
     *
     * @return BelongsTo<VehicleModel, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_id');
    }

    /**
     * Las factories del package viven fuera del namespace que Laravel adivina; se enlazan a mano.
     */
    protected static function newFactory(): DeliveryRecordFactory
    {
        return DeliveryRecordFactory::new();
    }
}
