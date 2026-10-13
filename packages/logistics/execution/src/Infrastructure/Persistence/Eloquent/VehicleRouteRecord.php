<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;

/**
 * Fila de la tabla `execution_driver_routes`. PK uuid string no incremental (UUID v7 del dominio).
 *
 * Es detalle de Infrastructure: nunca cruza hacia Application ni Domain. El mapeo a/desde el agregado
 * lo hace VehicleRouteMapper.
 *
 * @property string $id
 * @property string $vehicle_id
 * @property string $route_date
 * @property string $status
 */
final class VehicleRouteRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'execution_driver_routes';

    protected $guarded = [];

    /**
     * Paradas de la ruta, en orden de reparto.
     *
     * @return HasMany<RouteStopRecord, $this>
     */
    public function stops(): HasMany
    {
        return $this->hasMany(RouteStopRecord::class, 'route_id')->orderBy('sequence');
    }

    /**
     * Camión de la ruta. Relación de solo lectura hacia Fleet para mostrar la placa en vez del UUID.
     *
     * @return BelongsTo<VehicleModel, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_id');
    }
}
