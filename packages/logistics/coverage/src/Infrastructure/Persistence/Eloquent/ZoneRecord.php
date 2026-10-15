<?php

declare(strict_types=1);

namespace Logistics\Coverage\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\Factory\ZoneRecordFactory;

/**
 * Registro Eloquent de la tabla coverage_zones.
 *
 * La columna espacial `area` (geometry(Polygon,4326)) no se mapea como atributo normal: el repositorio
 * la lee y escribe por SQL con funciones PostGIS (ST_GeomFromGeoJSON / ST_AsGeoJSON). Este modelo
 * define la tabla, la conexión y los casts de las columnas no espaciales, y es el punto de anclaje
 * para el futuro admin de Filament.
 *
 * @property string               $id
 * @property string               $name
 * @property string|null          $parent_area_id
 * @property array<string, mixed> $rules
 * @property bool                 $active
 * @property-read GeoAreaRecord|null $parentArea área administrativa padre (nombres legibles)
 *
 * @use HasFactory<ZoneRecordFactory>
 */
final class ZoneRecord extends Model
{
    /** @use HasFactory<ZoneRecordFactory> */
    use HasFactory;

    public const string TABLE = 'coverage_zones';

    protected $table = self::TABLE;

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = ['id', 'name', 'parent_area_id', 'rules', 'active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * Factory del package (vive en src/ para autoload PSR-4).
     *
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return ZoneRecordFactory::new();
    }

    /**
     * Área administrativa padre a la que cuelga la zona.
     *
     * Permite al admin mostrar el nombre del área (ej. "La Paz") en lugar del UUID y eager-loadearla.
     *
     * @return BelongsTo<GeoAreaRecord, $this>
     */
    public function parentArea(): BelongsTo
    {
        return $this->belongsTo(GeoAreaRecord::class, 'parent_area_id');
    }
}
