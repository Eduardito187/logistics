<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\DepartureFactory;

/**
 * Modelo Eloquent de la tabla `fleetops_departures`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio. El vehículo se referencia
 * por su id como texto (UUID de fleet) sin FK dura.
 *
 * @property string                              $id
 * @property string                              $vehicle_id
 * @property string|null                         $driver_id
 * @property list<string>                        $helper_ids
 * @property string|null                         $destination
 * @property string|null                         $route_ref
 * @property string|null                         $load_description
 * @property float|null                          $load_weight_kg
 * @property list<array{name: string, qty: int}> $load_items
 * @property int|null                            $odometer_out_km
 * @property int|null                            $odometer_in_km
 * @property string                              $status
 * @property Carbon                              $departed_at
 * @property Carbon|null                         $returned_at
 * @property string|null                         $note
 */
final class DepartureRecord extends Model
{
    /** @use HasFactory<DepartureFactory> */
    use HasFactory;

    protected $table = 'fleetops_departures';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * Fábrica de datos de demo/pruebas de este record (ver DepartureFactory).
     */
    protected static function newFactory(): DepartureFactory
    {
        return DepartureFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'helper_ids' => 'array',
            'load_items' => 'array',
            'load_weight_kg' => 'float',
            'odometer_out_km' => 'integer',
            'odometer_in_km' => 'integer',
            'departed_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }
}
