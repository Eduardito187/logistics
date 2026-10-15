<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\RefuelFactory;

/**
 * Modelo Eloquent de la tabla `fleetops_refuels`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio. El vehículo y el conductor
 * se guardan como ids en texto (UUID de `fleet`), sin FK dura: el contexto es desacoplable.
 *
 * @property string      $id
 * @property string      $vehicle_id
 * @property string|null $driver_id
 * @property string      $fuel_type
 * @property float       $liters
 * @property int         $price_per_liter_cents
 * @property string      $currency
 * @property int         $total_cost_cents
 * @property int|null    $odometer_km
 * @property string|null $station
 * @property bool        $full_tank
 * @property Carbon      $refueled_at
 * @property string|null $note
 */
final class RefuelRecord extends Model
{
    /** @use HasFactory<RefuelFactory> */
    use HasFactory;

    protected $table = 'fleetops_refuels';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * Fábrica de datos de demo/pruebas de este record (ver RefuelFactory).
     */
    protected static function newFactory(): RefuelFactory
    {
        return RefuelFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'liters' => 'float',
            'price_per_liter_cents' => 'integer',
            'total_cost_cents' => 'integer',
            'odometer_km' => 'integer',
            'full_tank' => 'boolean',
            'refueled_at' => 'datetime',
        ];
    }
}
