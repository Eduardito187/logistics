<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Fila de la tabla tracking_satisfaction_surveys. Detalle de infraestructura: el dominio no lo conoce;
 * lo usa el repositorio Eloquent para mapear hacia/desde el agregado SatisfactionSurvey, y la capa de
 * presentación (Filament) para listar respuestas con la referencia humana del envío.
 *
 * La pk es el UUID de la respuesta (string, no autoincremental). shipment_id es único (una por envío).
 *
 * @property string      $id
 * @property string      $shipment_id
 * @property string      $token
 * @property int         $rating
 * @property string|null $comment
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SatisfactionSurveyRecord extends Model
{
    public $incrementing = false;

    protected $table = 'tracking_satisfaction_surveys';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'shipment_id',
        'token',
        'rating',
        'comment',
        'reason',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'rating' => 'integer',
    ];

    /**
     * Envío calificado. Relación de solo lectura para mostrar en el back office la referencia humana
     * (external_id + cliente) en vez del UUID crudo de shipment_id. La FK local es shipment_id.
     *
     * @return BelongsTo<ShipmentRecord, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(ShipmentRecord::class, 'shipment_id');
    }
}
