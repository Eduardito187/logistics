<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Modelo Eloquent de la bitácora `fleet_vehicle_fitness_log` (append-only). Detalle de infraestructura.
 *
 * @property string                 $id
 * @property string                 $vehicle_id
 * @property string                 $action
 * @property Carbon                 $occurred_at
 * @property string|null            $reason
 * @property list<string>|null      $failed_items
 * @property string|null            $inspection_id
 * @property string|null            $actor_id
 * @property string|null            $actor_label
 * @property-read VehicleModel|null $vehicle
 */
final class VehicleFitnessLogModel extends Model
{
    protected $table = 'fleet_vehicle_fitness_log';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'failed_items' => 'array',
        ];
    }

    /**
     * Vehículo de la entrada; permite mostrar su placa (nunca el UUID).
     *
     * @return BelongsTo<VehicleModel, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_id');
    }
}
