<?php

declare(strict_types=1);

namespace Logistics\Network\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fila de la tabla receiving_docks. Es un detalle de infraestructura: el dominio no lo conoce, solo lo
 * usa el repositorio Eloquent para mapear hacia y desde el agregado ReceivingDock.
 *
 * La pk es el UUID del andén (string, no autoincremental).
 *
 * @property string $id
 * @property string $warehouse_id
 * @property string $code
 * @property string $name
 * @property int    $appointments_per_slot
 * @property bool   $active
 */
final class ReceivingDockRecord extends Model
{
    public $incrementing = false;

    protected $table = 'receiving_docks';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'warehouse_id',
        'code',
        'name',
        'appointments_per_slot',
        'active',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'appointments_per_slot' => 'integer',
        'active' => 'boolean',
    ];

    /**
     * Almacén central (nodo) al que pertenece el andén. Proyección de lectura para el admin: muestra el
     * código/nombre del almacén en vez del UUID y permite eager-loadearlo sin N+1.
     *
     * @return BelongsTo<NodeRecord, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(NodeRecord::class, 'warehouse_id');
    }
}
