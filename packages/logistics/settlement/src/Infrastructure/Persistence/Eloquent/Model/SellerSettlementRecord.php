<?php

declare(strict_types=1);

namespace Logistics\Settlement\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Logistics\Settlement\Infrastructure\Persistence\Eloquent\Factory\SellerSettlementFactory;

/**
 * Modelo Eloquent de la tabla `seller_settlements`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio. Los montos van en centavos
 * (entero) + moneda; la comisión también guarda sus basis points para trazar el cálculo. Los fees
 * diferidos (storage/fulfillment/last_mile) hoy son 0 (hueco de Fases 2–3).
 *
 * @property string      $id
 * @property string      $seller_id
 * @property string      $period_from
 * @property string      $period_to
 * @property int         $gross_cents
 * @property string      $currency
 * @property int         $commission_basis_points
 * @property int         $commission_cents
 * @property int         $fixed_fee_cents
 * @property int         $storage_fee_cents
 * @property int         $fulfillment_fee_cents
 * @property int         $last_mile_fee_cents
 * @property int         $net_cents
 * @property string      $status
 * @property string|null $payment_reference
 */
final class SellerSettlementRecord extends Model
{
    /** @use HasFactory<SellerSettlementFactory> */
    use HasFactory;

    protected $table = 'seller_settlements';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected static function newFactory(): SellerSettlementFactory
    {
        return SellerSettlementFactory::new();
    }

    /**
     * Líneas (despachos) de la liquidación.
     *
     * @return HasMany<SellerSettlementLineRecord, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SellerSettlementLineRecord::class, 'settlement_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // Las fechas de periodo se guardan como columnas `date` pero se leen como string `YYYY-MM-DD`
        // (sin cast a Carbon): el VO SettlementPeriod del dominio trabaja con el texto crudo.
        return [
            'gross_cents' => 'integer',
            'commission_basis_points' => 'integer',
            'commission_cents' => 'integer',
            'fixed_fee_cents' => 'integer',
            'storage_fee_cents' => 'integer',
            'fulfillment_fee_cents' => 'integer',
            'last_mile_fee_cents' => 'integer',
            'net_cents' => 'integer',
        ];
    }
}
