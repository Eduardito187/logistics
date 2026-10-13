<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Tracking\Database\Factories\TrackingRecordFactory;

/**
 * Fila de la tabla tracking_shipments. Es un detalle de infraestructura: el dominio no lo conoce, solo
 * lo usa el repositorio Eloquent para mapear hacia y desde el agregado ShipmentTracking.
 *
 * La pk es el UUID del seguimiento (string, no autoincremental). token y shipment_id son únicos.
 *
 * @property string      $id
 * @property string      $shipment_id
 * @property string      $token
 * @property string      $status
 * @property int         $remaining_stops
 * @property string|null $route_id
 * @property int|null    $route_position
 * @property Carbon|null $eta
 * @property string|null $last_lat
 * @property string|null $last_lng
 * @property Carbon|null $last_position_at
 */
final class TrackingRecord extends Model
{
    /** @use HasFactory<TrackingRecordFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $table = 'tracking_shipments';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'shipment_id',
        'token',
        'status',
        'remaining_stops',
        'route_id',
        'route_position',
        'eta',
        'last_lat',
        'last_lng',
        'last_position_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'remaining_stops' => 'integer',
        'route_position' => 'integer',
        'eta' => 'datetime',
        'last_position_at' => 'datetime',
    ];

    /**
     * Envío al que pertenece este seguimiento. Permite mostrar en el back office la referencia humana
     * del envío (external_id, cliente) en vez del UUID crudo de shipment_id.
     *
     * Es una relación de solo lectura para la capa de presentación: el seguimiento se alimenta por
     * proyección, nunca escribe sobre el envío. La FK local es shipment_id.
     *
     * @return BelongsTo<ShipmentRecord, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(ShipmentRecord::class, 'shipment_id');
    }

    /**
     * Resuelve la factory del modelo. Se declara explícitamente porque el modelo vive en un namespace
     * de package (no en App\Models), así que el adivinador por convención de Laravel no la encontraría.
     */
    protected static function newFactory(): TrackingRecordFactory
    {
        return TrackingRecordFactory::new();
    }
}
