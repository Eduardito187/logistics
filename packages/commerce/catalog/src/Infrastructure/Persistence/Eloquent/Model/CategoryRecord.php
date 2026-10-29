<?php

declare(strict_types=1);

namespace Commerce\Catalog\Infrastructure\Persistence\Eloquent\Model;

use Commerce\Catalog\Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent de la tabla `commerce_categories`. Detalle de infraestructura: nunca cruza a Domain.
 *
 * @property string      $id
 * @property string|null $parent_id
 * @property string      $name
 * @property string      $slug
 */
final class CategoryRecord extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected $table = 'commerce_categories';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }
}
