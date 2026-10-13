<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

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
 * @property string|null $eta
 * @property string|null $last_lat
 * @property string|null $last_lng
 * @property string|null $last_position_at
 */
final class TrackingRecord extends Model
{
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
        'eta',
        'last_lat',
        'last_lng',
        'last_position_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'remaining_stops' => 'integer',
    ];
}
