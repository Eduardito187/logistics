<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\StreetStatsFactory;

/**
 * Modelo Eloquent de la tabla `telemetry_street_stats`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental); la identidad estable del tramo es `segment_key` (único).
 *
 * @property string                              $id
 * @property string                              $segment_key
 * @property string|null                         $name
 * @property list<array{lat: float, lng: float}> $geometry
 * @property int                                 $sample_count
 * @property float                               $avg_speed_kph
 * @property float                               $min_speed_kph
 * @property int                                 $total_seconds
 * @property Carbon|null                         $last_sampled_at
 * @property list<int>                           $hour_histogram
 */
final class StreetStatsRecord extends Model
{
    /** @use HasFactory<StreetStatsFactory> */
    use HasFactory;

    protected $table = 'telemetry_street_stats';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * Fábrica de datos de demo/pruebas de este record (ver StreetStatsFactory).
     */
    protected static function newFactory(): StreetStatsFactory
    {
        return StreetStatsFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'geometry' => 'array',
            'hour_histogram' => 'array',
            'sample_count' => 'integer',
            'total_seconds' => 'integer',
            'avg_speed_kph' => 'float',
            'min_speed_kph' => 'float',
            'last_sampled_at' => 'datetime',
        ];
    }
}
