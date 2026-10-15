<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Logistics\Promise\Database\Factories\PromiseFactory;

/**
 * Fila de la tabla `promises`. PK uuid string no incremental (UUID v7 generado por el dominio).
 *
 * Es detalle de Infrastructure: nunca cruza hacia Application ni Domain. El mapeo a/desde el agregado
 * lo hace PromiseMapper.
 *
 * @property string      $id
 * @property string      $shipment_ref
 * @property string      $zone_id
 * @property string      $slot_date
 * @property string      $time_slot
 * @property string      $status
 * @property string|null $reserved_until
 */
final class PromiseRecord extends Model
{
    /** @use HasFactory<PromiseFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'promises';

    protected $guarded = [];

    /**
     * El factory vive en el package (no en el namespace por defecto de Laravel), así que se resuelve
     * explícitamente.
     */
    protected static function newFactory(): PromiseFactory
    {
        return PromiseFactory::new();
    }
}
