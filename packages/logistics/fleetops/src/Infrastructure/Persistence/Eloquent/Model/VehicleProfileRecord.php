<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\VehicleProfileFactory;

/**
 * Modelo Eloquent de la tabla `fleetops_vehicle_profiles`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es `vehicle_id` (el id del vehículo en fleet, en texto): relación 1:1 con el vehículo, sin FK dura.
 * La columna `id` es el UUID interno del perfil, solo para dar identidad estable a los eventos de dominio.
 *
 * @property string      $vehicle_id
 * @property string      $id
 * @property string|null $brand
 * @property string|null $model
 * @property int|null    $year
 * @property string|null $fuel_type
 * @property string|null $tank_liters         capacidad del tanque en litros (decimal como texto)
 * @property string|null $vin
 * @property string|null $color
 * @property int|null    $current_odometer_km
 */
final class VehicleProfileRecord extends Model
{
    /** @use HasFactory<VehicleProfileFactory> */
    use HasFactory;

    protected $table = 'fleetops_vehicle_profiles';

    protected $primaryKey = 'vehicle_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * Fábrica de datos de demo/pruebas de este record (ver VehicleProfileFactory).
     */
    protected static function newFactory(): VehicleProfileFactory
    {
        return VehicleProfileFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'current_odometer_km' => 'integer',
        ];
    }
}
