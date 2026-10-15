<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\PingFactory;

/**
 * Fila de la tabla `telemetry_pings` (append-only). Detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio.
 *
 * @property string      $id
 * @property string      $vehicle_id
 * @property string|null $driver_id
 * @property string|null $device_id
 * @property string|null $route_id
 * @property float       $lat
 * @property float       $lng
 * @property float|null  $speed_kph
 * @property int|null    $heading
 * @property float|null  $accuracy_m
 * @property bool|null   $ignition
 * @property int|null    $odometer_m
 * @property Carbon      $recorded_at
 * @property Carbon      $received_at
 * @property string      $source
 * @property string|null $trip_id
 */
final class PingRecord extends Model
{
    /** @use HasFactory<PingFactory> */
    use HasFactory;

    protected $table = 'telemetry_pings';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected static function newFactory(): PingFactory
    {
        return PingFactory::new();
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
            'accuracy_m' => 'float',
            'ignition' => 'boolean',
            'odometer_m' => 'integer',
            'recorded_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }
}
