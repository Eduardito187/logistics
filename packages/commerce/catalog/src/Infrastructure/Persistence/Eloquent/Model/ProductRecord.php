<?php

declare(strict_types=1);

namespace Commerce\Catalog\Infrastructure\Persistence\Eloquent\Model;

use Commerce\Catalog\Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent de la tabla `commerce_products`. Detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (lo genera el dominio). Variantes y medios se guardan como JSONB en la
 * misma fila (son value objects del agregado, no entidades consultables aparte en esta fase).
 *
 * @property string                                                                    $id
 * @property string|null                                                               $seller_id
 * @property string|null                                                               $category_id
 * @property string                                                                    $name
 * @property string|null                                                               $description
 * @property string                                                                    $canonical_sku
 * @property string                                                                    $status
 * @property list<array{sku: string, name: string, attributes: array<string, string>}> $variants
 * @property list<array{path: string, alt: string, position: int}>                     $media
 */
final class ProductRecord extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $table = 'commerce_products';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'variants' => 'array',
        'media' => 'array',
    ];

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
