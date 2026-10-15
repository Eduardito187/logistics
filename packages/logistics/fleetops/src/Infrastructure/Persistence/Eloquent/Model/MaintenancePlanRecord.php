<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\MaintenancePlanFactory;

/**
 * Fila de la tabla `fleetops_maintenance_plans`. Detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio. El vehículo se referencia
 * por su id como texto (UUID de fleet), sin FK dura (contexto desacoplable).
 *
 * @property string      $id
 * @property string      $vehicle_id
 * @property string      $service_kind
 * @property int|null    $interval_km
 * @property int|null    $interval_days
 * @property int|null    $last_done_km
 * @property Carbon|null $last_done_at
 * @property int|null    $next_due_km
 * @property Carbon|null $next_due_at
 * @property bool        $active
 */
final class MaintenancePlanRecord extends Model
{
    /** @use HasFactory<MaintenancePlanFactory> */
    use HasFactory;

    protected $table = 'fleetops_maintenance_plans';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * Fábrica de datos de demo/pruebas de este record (ver MaintenancePlanFactory).
     */
    protected static function newFactory(): MaintenancePlanFactory
    {
        return MaintenancePlanFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'interval_km' => 'integer',
            'interval_days' => 'integer',
            'last_done_km' => 'integer',
            'last_done_at' => 'datetime',
            'next_due_km' => 'integer',
            'next_due_at' => 'datetime',
            'active' => 'boolean',
        ];
    }
}
