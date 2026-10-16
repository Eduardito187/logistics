<?php

declare(strict_types=1);

namespace Logistics\Returns\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent de la tabla `return_lines` (línea de una devolución). Detalle de infraestructura.
 *
 * @property string $id
 * @property string $return_id
 * @property string $sku
 * @property int    $quantity
 * @property string $reason
 */
final class ReturnLineRecord extends Model
{
    protected $table = 'return_lines';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'integer',
    ];
}
