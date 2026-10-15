<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo Eloquent de la tabla `fleet_pre_trip_checklists`. Detalle de infraestructura: no cruza a Domain.
 *
 * @property string              $id
 * @property string              $vehicle_id
 * @property string              $driver_id
 * @property array<string, bool> $items
 * @property bool                $passed
 * @property string              $performed_on
 * @property string|null         $notes
 * @property-read VehicleModel|null $vehicle
 * @property-read DriverModel|null  $driver
 */
final class PreTripInspectionModel extends Model
{
    protected $table = 'fleet_pre_trip_checklists';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // `performed_on` se deja sin cast: Postgres devuelve la fecha como texto 'Y-m-d'.
        return [
            'items' => 'array',
            'passed' => 'boolean',
        ];
    }

    /**
     * Vehículo inspeccionado. Permite mostrar su placa vía `vehicle.plate`.
     *
     * @return BelongsTo<VehicleModel, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_id');
    }

    /**
     * Chofer que ejecutó la inspección. Permite mostrar su nombre vía `driver.name`.
     *
     * @return BelongsTo<DriverModel, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(DriverModel::class, 'driver_id');
    }
}
