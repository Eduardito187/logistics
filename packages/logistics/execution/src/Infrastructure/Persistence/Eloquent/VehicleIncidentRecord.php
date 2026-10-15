<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;

/**
 * Fila de la tabla `execution_vehicle_incidents`. PK uuid string no incremental (UUID v7 del dominio).
 *
 * Es detalle de Infrastructure: nunca cruza hacia Application ni Domain. El mapeo a/desde el agregado
 * lo hace EloquentVehicleIncidentRepository.
 *
 * @property string       $id
 * @property string       $route_id
 * @property string       $vehicle_id
 * @property string       $severity
 * @property string       $type
 * @property string|null  $note
 * @property string|null  $lat
 * @property string|null  $lng
 * @property string|null  $reported_by_user
 * @property string|null  $reported_by_device
 * @property list<string> $affected_shipment_ids
 * @property string|null  $reschedule_date
 * @property int|null     $delay_minutes
 * @property string       $occurred_at
 * @property string|null  $idempotency_key
 */
final class VehicleIncidentRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'execution_vehicle_incidents';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'affected_shipment_ids' => 'array',
        'delay_minutes' => 'integer',
    ];

    /**
     * Camión del incidente. Relación de solo lectura hacia Fleet para mostrar la placa en vez del UUID.
     *
     * @return BelongsTo<VehicleModel, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_id');
    }

    /**
     * Ruta en la que ocurrió el incidente.
     *
     * @return BelongsTo<VehicleRouteRecord, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(VehicleRouteRecord::class, 'route_id');
    }
}
