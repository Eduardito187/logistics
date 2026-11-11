<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de `label_printers`. Detalle de infraestructura: el repositorio la mapea hacia/desde el agregado
 * Printer, y Filament la usa solo como proyección de lectura (toda mutación va por el CommandBus).
 *
 * @property string      $id
 * @property string      $name
 * @property string|null $model
 * @property string      $host
 * @property int         $port
 * @property string      $language
 * @property string|null $node_id
 * @property string|null $location
 * @property bool        $active
 */
final class PrinterRecord extends Model
{
    public $incrementing = false;

    protected $table = 'label_printers';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'name',
        'model',
        'host',
        'port',
        'language',
        'node_id',
        'location',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'active' => 'boolean',
        ];
    }
}
