<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\Factories\PhysicalUnitRecordFactory;

/**
 * Fila de la tabla `shipment_units` (bultos). PK uuid string no incremental.
 *
 * @property string $id
 * @property string $shipment_id
 * @property string $line_id
 * @property int    $unit_index
 * @property int    $unit_total
 * @property int    $piece_index
 * @property int    $piece_total
 */
final class PhysicalUnitRecord extends Model
{
    /** @use HasFactory<PhysicalUnitRecordFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'shipment_units';

    protected $guarded = [];

    /**
     * Factory del contexto (vive en Infrastructure junto al modelo, no en la app).
     */
    protected static function newFactory(): PhysicalUnitRecordFactory
    {
        return PhysicalUnitRecordFactory::new();
    }

    /**
     * Pedido al que pertenece el bulto (para selects y columnas del admin).
     *
     * @return BelongsTo<ShipmentRecord, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(ShipmentRecord::class, 'shipment_id');
    }

    /**
     * Línea de producto que originó el bulto.
     *
     * @return BelongsTo<ShipmentLineRecord, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(ShipmentLineRecord::class, 'line_id');
    }
}
