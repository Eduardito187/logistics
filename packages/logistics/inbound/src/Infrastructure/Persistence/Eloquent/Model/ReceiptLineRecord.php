<?php

declare(strict_types=1);

namespace Logistics\Inbound\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent de la tabla `inbound_receipt_lines` (línea recibida con QC). Detalle de infraestructura.
 *
 * @property string $id
 * @property string $goods_receipt_id
 * @property string $sku
 * @property int    $accepted_quantity
 * @property int    $rejected_quantity
 * @property int    $damaged_quantity
 * @property string $qc_outcome
 */
final class ReceiptLineRecord extends Model
{
    protected $table = 'inbound_receipt_lines';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'accepted_quantity' => 'integer',
        'rejected_quantity' => 'integer',
        'damaged_quantity' => 'integer',
    ];
}
