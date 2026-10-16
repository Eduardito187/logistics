<?php

declare(strict_types=1);

namespace Logistics\Sellers\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Logistics\Sellers\Database\Factories\SellerSkuFactory;

/**
 * Modelo Eloquent de la tabla `seller_skus`. Clave natural: (seller_id, seller_sku).
 *
 * @property string      $id
 * @property string      $seller_id
 * @property string      $seller_sku
 * @property string|null $sap_material
 */
final class SellerSkuRecord extends Model
{
    /** @use HasFactory<SellerSkuFactory> */
    use HasFactory;

    protected $table = 'seller_skus';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected static function newFactory(): SellerSkuFactory
    {
        return SellerSkuFactory::new();
    }
}
