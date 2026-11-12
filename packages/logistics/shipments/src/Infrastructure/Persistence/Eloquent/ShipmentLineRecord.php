<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\Factories\ShipmentLineRecordFactory;

/**
 * Fila de la tabla `shipment_lines`. PK uuid string no incremental.
 *
 * @property string      $id
 * @property string      $shipment_id
 * @property string      $sku
 * @property string      $product_name
 * @property string|null $product_image_url
 * @property string|null $unit_price
 * @property string|null $unit_price_currency
 * @property int         $quantity
 * @property string|null $unit_weight_kg      foto de medidas por unidad (Fase 2.1), kg
 * @property string|null $unit_volume_m3      foto de medidas por unidad (Fase 2.1), m³
 * @property string|null $measurements_source product | category | default
 */
final class ShipmentLineRecord extends Model
{
    /** @use HasFactory<ShipmentLineRecordFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'shipment_lines';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'unit_price' => 'decimal:2',
        'unit_weight_kg' => 'decimal:3',
        'unit_volume_m3' => 'decimal:6',
    ];

    /**
     * Factory del contexto (vive en Infrastructure junto al modelo, no en la app).
     */
    protected static function newFactory(): ShipmentLineRecordFactory
    {
        return ShipmentLineRecordFactory::new();
    }

    /**
     * Pedido al que pertenece la línea (para selects y columnas del admin).
     *
     * @return BelongsTo<ShipmentRecord, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(ShipmentRecord::class, 'shipment_id');
    }

    /**
     * Bultos generados a partir de esta línea.
     *
     * @return HasMany<PhysicalUnitRecord, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(PhysicalUnitRecord::class, 'line_id');
    }
}
