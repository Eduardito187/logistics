<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Fila de `telemetry_stop_etas`: ETA dinámica de una parada restante (una por envío pendiente).
 *
 * @property string      $shipment_id
 * @property string      $vehicle_id
 * @property int         $sequence
 * @property float|null  $stop_lat
 * @property float|null  $stop_lng
 * @property Carbon|null $eta_at
 * @property int|null    $remaining_distance_m
 * @property int|null    $remaining_duration_s
 * @property string      $method
 * @property Carbon      $position_recorded_at
 * @property Carbon      $computed_at
 */
final class StopEtaRecord extends Model
{
    protected $table = 'telemetry_stop_etas';

    protected $primaryKey = 'shipment_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'stop_lat' => 'float',
            'stop_lng' => 'float',
            'eta_at' => 'datetime',
            'remaining_distance_m' => 'integer',
            'remaining_duration_s' => 'integer',
            'position_recorded_at' => 'datetime',
            'computed_at' => 'datetime',
        ];
    }
}
