<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\TripFactory;

/**
 * Fila de la tabla `telemetry_trips`. Detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio.
 *
 * @property string      $id
 * @property string      $vehicle_id
 * @property string|null $driver_id
 * @property string|null $route_id
 * @property string      $status
 * @property Carbon      $started_at
 * @property Carbon|null $ended_at
 * @property float       $start_lat
 * @property float       $start_lng
 * @property float|null  $end_lat
 * @property float|null  $end_lng
 * @property float       $distance_m
 * @property int         $duration_s
 * @property int         $moving_s
 * @property int         $idle_s
 * @property float       $max_speed_kph
 * @property int         $ping_count
 */
final class TripRecord extends Model
{
    /** @use HasFactory<TripFactory> */
    use HasFactory;

    protected $table = 'telemetry_trips';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected static function newFactory(): TripFactory
    {
        return TripFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'start_lat' => 'float',
            'start_lng' => 'float',
            'end_lat' => 'float',
            'end_lng' => 'float',
            'distance_m' => 'float',
            'duration_s' => 'integer',
            'moving_s' => 'integer',
            'idle_s' => 'integer',
            'max_speed_kph' => 'float',
            'ping_count' => 'integer',
        ];
    }
}
