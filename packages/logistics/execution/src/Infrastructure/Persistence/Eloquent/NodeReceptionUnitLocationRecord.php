<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fila de la tabla `execution_node_reception_unit_locations`: ubicación en estante VIGENTE de un producto
 * dentro de una recepción en nodo. Única por (recepción, producto); una reubicación reescribe la fila.
 *
 * Es detalle de Infrastructure: el mapeo a/desde el agregado lo hace NodeReceptionMapper.
 *
 * @property string $id
 * @property string $node_reception_id
 * @property string $node_id
 * @property string $unit_id
 * @property string $location
 * @property string $located_at
 * @property string $device_id
 * @property string $user_id
 */
final class NodeReceptionUnitLocationRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'execution_node_reception_unit_locations';

    protected $guarded = [];

    /**
     * Recepción a la que pertenece la ubicación.
     *
     * @return BelongsTo<NodeReceptionRecord, $this>
     */
    public function reception(): BelongsTo
    {
        return $this->belongsTo(NodeReceptionRecord::class, 'node_reception_id');
    }
}
