<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fila de la tabla `planning_plans`. PK uuid string no incremental (UUID v7 generado por el dominio).
 *
 * Es detalle de Infrastructure: nunca cruza hacia Application ni Domain. El mapeo a/desde el agregado
 * lo hace PlanMapper.
 *
 * @property string $id
 * @property string $plan_date
 * @property string $vehicle_id
 * @property string $status
 */
final class PlanRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'planning_plans';

    protected $guarded = [];

    /**
     * @return HasMany<PlannedStopRecord, $this>
     */
    public function stops(): HasMany
    {
        return $this->hasMany(PlannedStopRecord::class, 'plan_id')->orderBy('sequence');
    }
}
