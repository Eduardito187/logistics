<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de la tabla `planning_plan_stops`. PK uuid string no incremental.
 *
 * `eta` guarda el instante de llegada (timestamptz) y `eta_minutes` los minutos acumulados desde la
 * salida; ambos pueden ser nulos hasta que el plan se propone. `lane` es nulo hasta recomputar la carga.
 *
 * @property string $id
 * @property string $plan_id
 * @property string $shipment_id
 * @property int $sequence
 * @property string|null $eta
 * @property int|null $eta_minutes
 * @property string|null $lane
 */
final class PlannedStopRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'planning_plan_stops';

    protected $guarded = [];
}
