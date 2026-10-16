<?php

declare(strict_types=1);

namespace Logistics\Settlement\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Logistics\Settlement\Infrastructure\Persistence\Eloquent\Factory\SellerSettlementLineFactory;

/**
 * Modelo Eloquent de la tabla `seller_settlement_lines` (un despacho del seller = una línea).
 *
 * @property string $id
 * @property string $settlement_id
 * @property string $shipment_id
 * @property string $dispatch_reference
 * @property int    $base_amount_cents
 * @property string $currency
 */
final class SellerSettlementLineRecord extends Model
{
    /** @use HasFactory<SellerSettlementLineFactory> */
    use HasFactory;

    protected $table = 'seller_settlement_lines';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected static function newFactory(): SellerSettlementLineFactory
    {
        return SellerSettlementLineFactory::new();
    }

    /**
     * Liquidación a la que pertenece la línea.
     *
     * @return BelongsTo<SellerSettlementRecord, $this>
     */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(SellerSettlementRecord::class, 'settlement_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_amount_cents' => 'integer',
        ];
    }
}
