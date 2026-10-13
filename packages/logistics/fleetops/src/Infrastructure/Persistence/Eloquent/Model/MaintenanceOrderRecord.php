<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\MaintenanceOrderFactory;

/**
 * Fila de la tabla `fleetops_maintenance_orders`. Detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio. El vehículo se referencia
 * por su id como texto (UUID de fleet), sin FK dura (contexto desacoplable).
 *
 * @property string                                                                                  $id
 * @property string                                                                                  $vehicle_id
 * @property string                                                                                  $type
 * @property string                                                                                  $status
 * @property int|null                                                                                $odometer_km
 * @property string|null                                                                             $workshop
 * @property list<array{description: string, qty: int, part_cost_cents: int, labor_cost_cents: int}> $items
 * @property int                                                                                     $total_cost_cents
 * @property string                                                                                  $currency
 * @property Carbon|null                                                                             $scheduled_at
 * @property Carbon|null                                                                             $started_at
 * @property Carbon|null                                                                             $completed_at
 * @property string|null                                                                             $note
 */
final class MaintenanceOrderRecord extends Model
{
    /** @use HasFactory<MaintenanceOrderFactory> */
    use HasFactory;

    protected $table = 'fleetops_maintenance_orders';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * Fábrica de datos de demo/pruebas de este record (ver MaintenanceOrderFactory).
     */
    protected static function newFactory(): MaintenanceOrderFactory
    {
        return MaintenanceOrderFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'odometer_km' => 'integer',
            'items' => 'array',
            'total_cost_cents' => 'integer',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
