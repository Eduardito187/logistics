<?php

declare(strict_types=1);

namespace Logistics\Coverage\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\Factory\GeoAreaRecordFactory;

/**
 * Registro Eloquent de la tabla coverage_geo_areas (jerarquía administrativa).
 *
 * @property string      $id
 * @property string      $name
 * @property string      $level
 * @property string|null $parent_id
 * @property-read GeoAreaRecord|null $parent   área padre en el árbol (resuelve nombres legibles)
 * @property-read Collection<int, GeoAreaRecord> $children
 * @property-read Collection<int, ZoneRecord>    $zones
 *
 * @use HasFactory<GeoAreaRecordFactory>
 */
final class GeoAreaRecord extends Model
{
    /** @use HasFactory<GeoAreaRecordFactory> */
    use HasFactory;

    public const string TABLE = 'coverage_geo_areas';

    protected $table = self::TABLE;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = ['id', 'name', 'level', 'parent_id'];

    /**
     * Factory del package (vive en src/ para autoload PSR-4; la convención por defecto de Laravel
     * la buscaría fuera del package).
     *
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return GeoAreaRecordFactory::new();
    }

    /**
     * Área padre en la jerarquía administrativa (Departamento > Provincia > Municipio > Ciudad).
     *
     * Permite al admin mostrar el nombre del padre en vez del UUID y hacer eager-loading.
     *
     * @return BelongsTo<GeoAreaRecord, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Áreas hijas directas en el árbol administrativo.
     *
     * @return HasMany<GeoAreaRecord, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Zonas de cobertura ancladas a esta área administrativa.
     *
     * @return HasMany<ZoneRecord, $this>
     */
    public function zones(): HasMany
    {
        return $this->hasMany(ZoneRecord::class, 'parent_area_id');
    }
}
