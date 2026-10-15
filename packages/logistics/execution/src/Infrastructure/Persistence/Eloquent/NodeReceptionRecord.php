<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\Factory\NodeReceptionRecordFactory;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Fila de la tabla `execution_node_receptions`. PK uuid string no incremental (UUID v7 del dominio).
 *
 * Es detalle de Infrastructure: nunca cruza hacia Application ni Domain. El mapeo a/desde el agregado lo
 * hace NodeReceptionMapper.
 *
 * @property string       $id
 * @property string       $shipment_id
 * @property string       $origin_node_id
 * @property string       $destination_node_id
 * @property string       $corridor
 * @property string       $status
 * @property list<string> $expected_unit_ids
 * @property string       $opened_at
 * @property string|null  $reconciled_at
 */
final class NodeReceptionRecord extends Model
{
    /** @use HasFactory<NodeReceptionRecordFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'execution_node_receptions';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'expected_unit_ids' => 'array',
    ];

    /**
     * Bultos recibidos en el nodo (append-only).
     *
     * @return HasMany<NodeReceptionUnitRecord, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(NodeReceptionUnitRecord::class, 'node_reception_id');
    }

    /**
     * Envío que se recibe. Permite mostrar la referencia externa legible del pedido en el admin en vez
     * del UUID. Solo lectura, para columnas y títulos del panel.
     *
     * @return BelongsTo<ShipmentRecord, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(ShipmentRecord::class, 'shipment_id');
    }

    /**
     * Las factories del package viven fuera del namespace que Laravel adivina; se enlazan a mano.
     */
    protected static function newFactory(): NodeReceptionRecordFactory
    {
        return NodeReceptionRecordFactory::new();
    }
}
