<?php

declare(strict_types=1);

namespace Logistics\Inbound\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent de la tabla `inbound_asn_lines` (línea de un ASN). Detalle de infraestructura.
 *
 * @property string $id
 * @property string $asn_id
 * @property string $sku
 * @property int    $expected_quantity
 */
final class AsnLineRecord extends Model
{
    protected $table = 'inbound_asn_lines';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'expected_quantity' => 'integer',
    ];
}
