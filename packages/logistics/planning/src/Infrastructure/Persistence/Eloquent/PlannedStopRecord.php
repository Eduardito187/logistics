<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Logistics\Planning\Database\Factories\PlannedStopRecordFactory;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Fila de la tabla `planning_plan_stops`. PK uuid string no incremental.
 *
 * `eta` guarda el instante de llegada (timestamptz) y `eta_minutes` los minutos acumulados desde la
 * salida; ambos pueden ser nulos hasta que el plan se propone. `lane` es nulo hasta recomputar la carga.
 *
 * @property string      $id
 * @property string      $plan_id
 * @property string      $shipment_id
 * @property string|null $order_reference
 * @property string|null $customer_name
 * @property string|null $address
 * @property int         $sequence
 * @property string|null $eta
 * @property int|null    $eta_minutes
 * @property string|null $lane
 */
final class PlannedStopRecord extends Model
{
    /** @use HasFactory<PlannedStopRecordFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'planning_plan_stops';

    protected $guarded = [];

    /**
     * Plan (hoja de ruta del camión) al que pertenece la parada. Permite mostrar día y placa del plan
     * en lugar del UUID `plan_id`.
     *
     * @return BelongsTo<PlanRecord, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanRecord::class, 'plan_id');
    }

    /**
     * Envío entregado en esta parada. Relación de solo lectura hacia el contexto Shipments para mostrar
     * la referencia y el cliente (datos humanos) en lugar del UUID `shipment_id`.
     *
     * @return BelongsTo<ShipmentRecord, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(ShipmentRecord::class, 'shipment_id');
    }

    /**
     * Fábrica del modelo, en el namespace del package (no en Database\Factories del proyecto).
     */
    protected static function newFactory(): PlannedStopRecordFactory
    {
        return PlannedStopRecordFactory::new();
    }
}
