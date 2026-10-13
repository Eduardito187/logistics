<?php

declare(strict_types=1);

namespace Logistics\Coverage\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro Eloquent de la tabla coverage_geo_areas (jerarquía administrativa).
 *
 * @property string      $id
 * @property string      $name
 * @property string      $level
 * @property string|null $parent_id
 */
final class GeoAreaRecord extends Model
{
    public const string TABLE = 'coverage_geo_areas';

    protected $table = self::TABLE;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = ['id', 'name', 'level', 'parent_id'];
}
