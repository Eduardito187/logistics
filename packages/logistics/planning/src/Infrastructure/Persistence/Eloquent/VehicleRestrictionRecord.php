<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;

/**
 * Fila de `planning_vehicle_restrictions` (una por camión, PK = vehicle_id). Proyección de LECTURA para el
 * admin: toda escritura pasa por los comandos SetVehicleRestrictions / RemoveVehicleRestrictions.
 *
 * @property string            $vehicle_id
 * @property list<string>|null $allowed_load_types
 * @property list<string>|null $allowed_zones
 * @property list<string>|null $forbidden_zones
 * @property string|null       $max_height_m
 * @property string|null       $max_weight_kg
 * @property-read VehicleModel|null $vehicle
 */
final class VehicleRestrictionRecord extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'vehicle_id';

    protected $keyType = 'string';

    protected $table = 'planning_vehicle_restrictions';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'allowed_load_types' => 'array',
        'allowed_zones' => 'array',
        'forbidden_zones' => 'array',
    ];

    /**
     * Camión dueño de las restricciones (solo lectura hacia Flota, para mostrar la placa y no el UUID).
     *
     * @return BelongsTo<VehicleModel, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_id');
    }
}
