<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\VehiclePositionFactory;

/**
 * Fila de la tabla `telemetry_vehicle_positions`: la posición en vivo de un vehículo (una por vehículo).
 * La PK es el vehicle_id (string, del contexto fleet), no autoincremental.
 *
 * @property string      $vehicle_id
 * @property float       $lat
 * @property float       $lng
 * @property float|null  $speed_kph
 * @property int|null    $heading
 * @property bool|null   $ignition
 * @property bool        $moving
 * @property string|null $route_id
 * @property Carbon      $recorded_at
 */
final class VehiclePositionRecord extends Model
{
    /** @use HasFactory<VehiclePositionFactory> */
    use HasFactory;

    protected $table = 'telemetry_vehicle_positions';

    protected $primaryKey = 'vehicle_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected static function newFactory(): VehiclePositionFactory
    {
        return VehiclePositionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'speed_kph' => 'float',
            'heading' => 'integer',
            'ignition' => 'boolean',
            'moving' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }
}
