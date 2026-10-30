<?php

declare(strict_types=1);

namespace Commerce\Pricing\Infrastructure\Persistence\Eloquent\Model;

use Commerce\Pricing\Database\Factories\PriceListFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modelo Eloquent de la tabla `commerce_price_lists`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio.
 *
 * @property string $id
 * @property string $name
 * @property string $currency
 * @property string $channel
 * @property string $status
 */
final class PriceListRecord extends Model
{
    /** @use HasFactory<PriceListFactory> */
    use HasFactory;

    protected $table = 'commerce_price_lists';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected static function newFactory(): PriceListFactory
    {
        return PriceListFactory::new();
    }

    /**
     * Precios (ítems) de la lista.
     *
     * @return HasMany<PriceListItemRecord, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PriceListItemRecord::class, 'price_list_id');
    }
}
