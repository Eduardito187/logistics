<?php

declare(strict_types=1);

namespace Logistics\Inbound\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Logistics\Inbound\Database\Factories\GoodsReceiptRecordFactory;

/**
 * Modelo Eloquent de la tabla `inbound_goods_receipts`. Detalle de infraestructura.
 *
 * @property string      $id
 * @property string      $asn_id
 * @property string      $warehouse_id
 * @property string      $owner_party_id
 * @property string      $fulfillment_mode
 * @property string|null $seller_sku_ref
 * @property bool        $labeled
 */
final class GoodsReceiptRecord extends Model
{
    /** @use HasFactory<GoodsReceiptRecordFactory> */
    use HasFactory;

    protected $table = 'inbound_goods_receipts';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'labeled' => 'boolean',
    ];

    protected static function newFactory(): GoodsReceiptRecordFactory
    {
        return GoodsReceiptRecordFactory::new();
    }

    /**
     * Líneas recibidas (sku + cantidades + QC).
     *
     * @return HasMany<ReceiptLineRecord, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ReceiptLineRecord::class, 'goods_receipt_id');
    }
}
