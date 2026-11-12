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
 * @property string|null                                                               $weight_kg
 * @property string|null                                                               $length_cm
 * @property string|null                                                               $width_cm
 * @property string|null                                                               $height_cm
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
        // Medidas físicas (Fase 2.0): decimal → string; el mapper las convierte a float.
        'weight_kg' => 'decimal:3',
        'length_cm' => 'decimal:2',
        'width_cm' => 'decimal:2',
        'height_cm' => 'decimal:2',
    ];

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
