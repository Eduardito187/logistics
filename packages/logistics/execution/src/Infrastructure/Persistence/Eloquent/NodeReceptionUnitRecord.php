<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\Factory\NodeReceptionUnitRecordFactory;

/**
 * Fila de la tabla `execution_node_reception_units`. Append-only: nunca se modifica ni se borra. PK uuid
 * string. El índice único en idempotency_key respalda la idempotencia offline a nivel de base.
 *
 * @property string $id
 * @property string $node_reception_id
 * @property string $unit_id
 * @property string $lat
 * @property string $lng
 * @property string $device_id
 * @property string $user_id
 * @property string $occurred_at
 * @property string $idempotency_key
 */
final class NodeReceptionUnitRecord extends Model
{
    /** @use HasFactory<NodeReceptionUnitRecordFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'execution_node_reception_units';

    protected $guarded = [];

    /**
     * Recepción a la que pertenece el bulto.
     *
     * @return BelongsTo<NodeReceptionRecord, $this>
     */
    public function reception(): BelongsTo
    {
        return $this->belongsTo(NodeReceptionRecord::class, 'node_reception_id');
    }

    /**
     * Las factories del package viven fuera del namespace que Laravel adivina; se enlazan a mano.
     */
    protected static function newFactory(): NodeReceptionUnitRecordFactory
    {
        return NodeReceptionUnitRecordFactory::new();
    }
}
