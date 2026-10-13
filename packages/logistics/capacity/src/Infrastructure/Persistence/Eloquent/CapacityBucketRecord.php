<?php

declare(strict_types=1);

namespace Logistics\Capacity\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de la tabla capacity_buckets. Es un detalle de infraestructura: el dominio no lo conoce, solo
 * lo usa el repositorio Eloquent para mapear hacia y desde el agregado CapacityBucket.
 *
 * La pk es el UUID del bucket (string, no autoincremental). total/used son decimales guardados como
 * string por Eloquent; el mapper los convierte a Quantity.
 *
 * @property string      $id
 * @property string|null $node_id
 * @property string|null $zone_id
 * @property string      $slot_date
 * @property string      $time_slot
 * @property string      $unit
 * @property string      $total
 * @property string      $used
 */
final class CapacityBucketRecord extends Model
{
    public $incrementing = false;

    protected $table = 'capacity_buckets';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'node_id',
        'zone_id',
        'slot_date',
        'time_slot',
        'unit',
        'total',
        'used',
    ];
}
