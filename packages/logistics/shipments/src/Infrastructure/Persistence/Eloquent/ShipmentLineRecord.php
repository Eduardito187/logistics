<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de la tabla `shipment_lines`. PK uuid string no incremental.
 *
 * @property string $id
 * @property string $shipment_id
 * @property string $sku
 * @property string $product_name
 * @property int $quantity
 */
final class ShipmentLineRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'shipment_lines';

    protected $guarded = [];
}
