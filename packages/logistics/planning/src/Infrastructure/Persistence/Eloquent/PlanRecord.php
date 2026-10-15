<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Planning\Database\Factories\PlanRecordFactory;

/**
 * Fila de la tabla `planning_plans`. PK uuid string no incremental (UUID v7 generado por el dominio).
 *
 * Es detalle de Infrastructure: nunca cruza hacia Application ni Domain. El mapeo a/desde el agregado
 * lo hace PlanMapper.
 *
 * @property string                    $id
 * @property string                    $plan_date
 * @property string                    $vehicle_id
 * @property string                    $status
 * @property array<string, mixed>|null $route_geometry
 * @property float|null                $route_distance_meters
 * @property float|null                $route_duration_seconds
 */
final class PlanRecord extends Model
{
    /** @use HasFactory<PlanRecordFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'planning_plans';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'route_geometry' => 'array',
        'route_distance_meters' => 'float',
        'route_duration_seconds' => 'float',
    ];

    /**
     * @return HasMany<PlannedStopRecord, $this>
     */
    public function stops(): HasMany
    {
        return $this->hasMany(PlannedStopRecord::class, 'plan_id')->orderBy('sequence');
    }

    /**
     * Camión del plan. Relación de solo lectura hacia el contexto Fleet para mostrar la placa (dato
     * humano) en lugar del UUID. No introduce acoplamiento de escritura: el admin nunca escribe aquí.
     *
     * @return BelongsTo<VehicleModel, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_id');
    }

    /**
     * Fábrica del modelo, en el namespace del package (no en Database\Factories del proyecto).
     */
    protected static function newFactory(): PlanRecordFactory
    {
        return PlanRecordFactory::new();
    }
}
