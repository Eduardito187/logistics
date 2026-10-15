<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\GeofenceFactory;

/**
 * Modelo Eloquent de la tabla `telemetry_geofences`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio. El polígono se guarda
 * como JSON (lista de pares [lat,lng]); el repo NO usa PostGIS.
 *
 * @property string                              $id
 * @property string                              $name
 * @property string                              $type
 * @property list<array{lat: float, lng: float}> $polygon
 * @property bool                                $active
 * @property string|null                         $node_ref
 */
final class GeofenceRecord extends Model
{
    /** @use HasFactory<GeofenceFactory> */
    use HasFactory;

    protected $table = 'telemetry_geofences';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * Fábrica de datos de demo/pruebas de este record (ver GeofenceFactory).
     */
    protected static function newFactory(): GeofenceFactory
    {
        return GeofenceFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'polygon' => 'array',
            'active' => 'boolean',
        ];
    }
}
