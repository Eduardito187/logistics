<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\OdometerReadingFactory;

/**
 * Modelo Eloquent de la tabla `fleetops_odometer_readings`. Es detalle de infraestructura: nunca cruza a
 * Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio. El vehículo se referencia
 * por su id como texto (UUID de fleet) sin FK dura.
 *
 * @property string      $id
 * @property string      $vehicle_id
 * @property int         $km
 * @property string      $source
 * @property Carbon      $read_at
 * @property string|null $note
 */
final class OdometerReadingRecord extends Model
{
    /** @use HasFactory<OdometerReadingFactory> */
    use HasFactory;

    protected $table = 'fleetops_odometer_readings';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * Fábrica de datos de demo/pruebas de este record (ver OdometerReadingFactory).
     */
    protected static function newFactory(): OdometerReadingFactory
    {
        return OdometerReadingFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'km' => 'integer',
            'read_at' => 'datetime',
        ];
    }
}
