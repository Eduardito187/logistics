<?php

declare(strict_types=1);

namespace Logistics\Inventory\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Modelo Eloquent de la tabla `inventory_movements`: ledger append-only de los movimientos de stock, para
 * trazabilidad y reportería por dueño. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * @property string      $id
 * @property string      $lot_id
 * @property string      $warehouse_id
 * @property string      $sku
 * @property string      $fulfillment_mode
 * @property string|null $owner_party_id
 * @property string      $movement_type
 * @property int         $quantity
 * @property string|null $reference
 * @property string|null $related_warehouse_id
 * @property Carbon      $occurred_at
 */
final class InventoryMovementRecord extends Model
{
    protected $table = 'inventory_movements';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'integer',
        'occurred_at' => 'datetime',
    ];
}
