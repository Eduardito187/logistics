<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de la tabla `planning_auto_plan_runs`. Detalle de Infrastructure: nunca cruza a Application ni
 * Domain. El mapeo a/desde el agregado AutoPlanRun lo hace el repositorio.
 *
 * @property string      $id
 * @property string      $plan_date
 * @property string      $strategy
 * @property string      $status
 * @property string      $requested_at
 * @property string|null $completed_at
 * @property int         $trucks_planned
 * @property int         $stops_planned
 * @property int         $unassigned_count
 * @property string|null $failure_reason
 */
final class AutoPlanRunRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'planning_auto_plan_runs';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trucks_planned' => 'integer',
            'stops_planned' => 'integer',
            'unassigned_count' => 'integer',
        ];
    }
}
