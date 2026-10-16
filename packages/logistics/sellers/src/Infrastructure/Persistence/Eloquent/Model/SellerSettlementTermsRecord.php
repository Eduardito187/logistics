<?php

declare(strict_types=1);

namespace Logistics\Sellers\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Logistics\Sellers\Database\Factories\SellerSettlementTermsFactory;

/**
 * Modelo Eloquent de la tabla `seller_settlement_terms` (0 o 1 por seller).
 *
 * El fee fijo se guarda en centavos (entero) + moneda; la comisión en basis points (entero).
 *
 * @property string $id
 * @property string $seller_id
 * @property int    $commission_basis_points
 * @property int    $fixed_fee_cents
 * @property string $currency
 * @property string $payment_cycle
 */
final class SellerSettlementTermsRecord extends Model
{
    /** @use HasFactory<SellerSettlementTermsFactory> */
    use HasFactory;

    protected $table = 'seller_settlement_terms';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected static function newFactory(): SellerSettlementTermsFactory
    {
        return SellerSettlementTermsFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'commission_basis_points' => 'integer',
            'fixed_fee_cents' => 'integer',
        ];
    }
}
