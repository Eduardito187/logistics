<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo Eloquent de la tabla `fleet_crews`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * @property string       $id
 * @property string       $driver_id
 * @property list<string> $helper_ids
 * @property string       $vehicle_id
 * @property string       $assembled_for
 * @property-read DriverModel|null  $driver
 * @property-read VehicleModel|null $vehicle
 */
final class CrewModel extends Model
{
    protected $table = 'fleet_crews';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // `assembled_for` se deja sin cast: Postgres devuelve la fecha como texto 'Y-m-d'.
        return [
            'helper_ids' => 'array',
        ];
    }

    /**
     * Chofer que conduce esta tripulación. Permite mostrar su nombre legible vía `driver.name`.
     *
     * @return BelongsTo<DriverModel, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(DriverModel::class, 'driver_id');
    }

    /**
     * Vehículo asignado a esta tripulación. Permite mostrar su placa vía `vehicle.plate`.
     *
     * @return BelongsTo<VehicleModel, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_id');
    }
}
