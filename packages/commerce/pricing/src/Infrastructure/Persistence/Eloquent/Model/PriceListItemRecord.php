<?php

declare(strict_types=1);

namespace Commerce\Pricing\Infrastructure\Persistence\Eloquent\Model;

use Commerce\Pricing\Database\Factories\PriceListItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent de la tabla `commerce_price_list_items`. Clave natural: (price_list_id, sku).
 *
 * El precio al público (IVA incluido) se guarda en centavos (entero) + moneda, nunca float.
 *
 * @property string $id
 * @property string $price_list_id
 * @property string $sku
 * @property int    $gross_amount_cents
 * @property string $currency
 */
final class PriceListItemRecord extends Model
{
    /** @use HasFactory<PriceListItemFactory> */
    use HasFactory;

    protected $table = 'commerce_price_list_items';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected static function newFactory(): PriceListItemFactory
    {
        return PriceListItemFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gross_amount_cents' => 'integer',
        ];
    }
}
