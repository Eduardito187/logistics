<?php

declare(strict_types=1);

namespace Logistics\Inventory\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Logistics\Inventory\Infrastructure\Persistence\Eloquent\Factory\InventoryLotRecordFactory;

/**
 * Modelo Eloquent de la tabla `inventory_lots`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID v7 en texto (no autoincremental): el id lo genera el dominio.
 *
 * @property string      $id
 * @property string      $warehouse_id
 * @property string      $sku
 * @property string      $fulfillment_mode
 * @property string|null $owner_party_id
 * @property string|null $seller_sku_ref
 * @property int         $available_quantity
 * @property int         $reserved_quantity
 * @property int         $unit_cost_cents
 * @property string      $currency
 * @property Carbon      $received_at
 * @property string|null $goods_receipt_ref
 */
final class InventoryLotRecord extends Model
{
    /** @use HasFactory<InventoryLotRecordFactory> */
    use HasFactory;

    protected $table = 'inventory_lots';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'available_quantity' => 'integer',
        'reserved_quantity' => 'integer',
        'unit_cost_cents' => 'integer',
        'received_at' => 'datetime',
    ];

    /**
     * Fábrica de datos de demo/pruebas de este record (ver InventoryLotRecordFactory).
     */
    protected static function newFactory(): InventoryLotRecordFactory
    {
        return InventoryLotRecordFactory::new();
    }
}
