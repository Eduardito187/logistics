<?php

declare(strict_types=1);

namespace Logistics\Capacity\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Logistics\Capacity\Infrastructure\Persistence\Eloquent\Factory\CapacityBucketFactory;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\GeoAreaRecord;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\ZoneRecord;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;

/**
 * Fila de la tabla capacity_buckets. Es un detalle de infraestructura: el dominio no lo conoce, solo
 * lo usa el repositorio Eloquent para mapear hacia y desde el agregado CapacityBucket.
 *
 * La pk es el UUID del bucket (string, no autoincremental). total/used son decimales guardados como
 * string por Eloquent; el mapper los convierte a Quantity.
 *
 * @property string      $id
 * @property string|null $node_id
 * @property string|null $zone_id
 * @property string|null $municipality_id
 * @property string      $slot_date
 * @property string      $time_slot
 * @property string      $unit
 * @property string      $total
 * @property string      $used
 *
 * @use HasFactory<CapacityBucketFactory>
 */
final class CapacityBucketRecord extends Model
{
    /** @use HasFactory<CapacityBucketFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $table = 'capacity_buckets';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'node_id',
        'zone_id',
        'municipality_id',
        'slot_date',
        'time_slot',
        'unit',
        'total',
        'used',
    ];

    /**
     * El package está en dont-discover y la factory vive en su propio namespace, así que se enlaza
     * explícitamente en vez de dejar que Laravel adivine `Database\Factories\...`.
     */
    protected static function newFactory(): CapacityBucketFactory
    {
        return CapacityBucketFactory::new();
    }

    /**
     * Nodo al que pertenece el cupo cuando su scope es un nodo. Proyección de lectura para el admin:
     * permite mostrar el código/nombre del nodo en vez del UUID crudo y eager-loadearlo sin N+1.
     *
     * @return BelongsTo<NodeRecord, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(NodeRecord::class, 'node_id');
    }

    /**
     * Zona a la que pertenece el cupo cuando su scope es una zona. Proyección de lectura para el admin:
     * permite mostrar el nombre de la zona en vez del UUID crudo y eager-loadearla sin N+1.
     *
     * @return BelongsTo<ZoneRecord, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ZoneRecord::class, 'zone_id');
    }

    /**
     * Municipio (área administrativa de coverage) al que pertenece el cupo cuando su scope es un
     * municipio. Proyección de lectura para el admin: muestra el nombre del municipio en vez del UUID y
     * permite eager-loadearlo sin N+1.
     *
     * @return BelongsTo<GeoAreaRecord, $this>
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(GeoAreaRecord::class, 'municipality_id');
    }
}
