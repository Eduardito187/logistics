<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fila de la tabla `execution_deliveries`. PK uuid string no incremental (UUID v7 del dominio).
 *
 * Es detalle de Infrastructure: nunca cruza hacia Application ni Domain. El mapeo a/desde el agregado
 * lo hace DeliveryMapper.
 *
 * @property string $id
 * @property string $shipment_id
 * @property string $vehicle_id
 * @property string $status
 * @property string|null $pod_confirmation
 * @property string|null $pod_photo_url
 * @property string|null $pod_captured_at
 * @property string|null $failure_reason
 */
final class DeliveryRecord extends Model
{
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
}
