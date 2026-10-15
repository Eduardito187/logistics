<?php

declare(strict_types=1);

namespace Logistics\Capacity\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de la tabla capacity_active_horizons (horizonte activo de cupos por alcance). Detalle de
 * infraestructura: la usa el repositorio Eloquent para mapear hacia y desde ActiveHorizon.
 *
 * @property string      $id
 * @property string|null $municipality_id
 * @property string      $active_until
 */
final class ActiveHorizonRecord extends Model
{
    public $incrementing = false;

    protected $table = 'capacity_active_horizons';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'municipality_id',
        'active_until',
    ];
}
