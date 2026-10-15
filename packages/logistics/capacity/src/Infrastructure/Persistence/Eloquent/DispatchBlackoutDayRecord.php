<?php

declare(strict_types=1);

namespace Logistics\Capacity\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\GeoAreaRecord;

/**
 * Fila de la tabla dispatch_blackout_days (día bloqueado de despacho). Detalle de infraestructura: el
 * dominio no la conoce; la usa el repositorio Eloquent para mapear hacia y desde DispatchBlackoutDay.
 *
 * @property string      $id
 * @property string      $blackout_date
 * @property string|null $municipality_id
 * @property string      $reason
 * @property string|null $created_by
 */
final class DispatchBlackoutDayRecord extends Model
{
    public $incrementing = false;

    protected $table = 'dispatch_blackout_days';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'blackout_date',
        'municipality_id',
        'reason',
        'created_by',
    ];

    /**
     * Municipio del bloqueo (área administrativa de coverage) cuando no es global. Proyección de lectura
     * para el admin: muestra el nombre del municipio en vez del UUID.
     *
     * @return BelongsTo<GeoAreaRecord, $this>
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(GeoAreaRecord::class, 'municipality_id');
    }
}
