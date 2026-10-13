<?php

declare(strict_types=1);

namespace Logistics\Network\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Logistics\Network\Database\Factories\NodeFactory;

/**
 * Fila de la tabla network_nodes. Es un detalle de infraestructura: el dominio no lo conoce, solo lo
 * usa el repositorio Eloquent para mapear hacia y desde el agregado Node.
 *
 * La pk es el UUID del nodo (string, no autoincremental). La columna geográfica `location`
 * (geography Point) no se mapea acá: la puebla el repositorio por SQL a partir de lat/lng.
 *
 * @property string      $id
 * @property string      $code
 * @property string      $name
 * @property string      $type
 * @property string      $lat
 * @property string      $lng
 * @property string      $status
 * @property string|null $department
 */
final class NodeRecord extends Model
{
    /** @use HasFactory<NodeFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $table = 'network_nodes';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'code',
        'name',
        'type',
        'lat',
        'lng',
        'status',
        'department',
    ];

    /**
     * Enlaza el record con su factory del package (las factories viven en database/factories).
     *
     * @return Factory<self>
     */
    protected static function newFactory(): Factory
    {
        return NodeFactory::new();
    }

    /**
     * Tramos que nacen en este nodo (este nodo es el origen).
     *
     * @return HasMany<LegRecord, $this>
     */
    public function outgoingLegs(): HasMany
    {
        return $this->hasMany(LegRecord::class, 'from_node_id');
    }

    /**
     * Tramos que terminan en este nodo (este nodo es el destino).
     *
     * @return HasMany<LegRecord, $this>
     */
    public function incomingLegs(): HasMany
    {
        return $this->hasMany(LegRecord::class, 'to_node_id');
    }
}
