<?php

declare(strict_types=1);

namespace Logistics\Network\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de la tabla network_nodes. Es un detalle de infraestructura: el dominio no lo conoce, solo lo
 * usa el repositorio Eloquent para mapear hacia y desde el agregado Node.
 *
 * La pk es el UUID del nodo (string, no autoincremental). La columna geográfica `location`
 * (geography Point) no se mapea acá: la puebla el repositorio por SQL a partir de lat/lng.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property string $lat
 * @property string $lng
 * @property string $status
 */
final class NodeRecord extends Model
{
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
    ];
}
