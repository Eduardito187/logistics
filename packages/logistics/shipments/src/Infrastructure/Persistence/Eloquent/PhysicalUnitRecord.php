<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

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
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'shipment_units';

    protected $guarded = [];
}
