<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\PhysicalUnitRecord;
use Logistics\Tracking\Database\Factories\UnitTrackingRecordFactory;

/**
 * Fila de la tabla tracking_units: el estado visible de cada bulto. Un seguimiento por bulto
 * (unit_id único). Es un detalle de infraestructura: el dominio solo lo usa a través del mapper.
 *
 * @property string      $id
 * @property string      $shipment_id
 * @property string      $unit_id
 * @property string      $status
 * @property string|null $last_location_id
 * @property Carbon|null $last_seen_at
 */
final class UnitTrackingRecord extends Model
{
    /** @use HasFactory<UnitTrackingRecordFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $table = 'tracking_units';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'shipment_id',
        'unit_id',
        'status',
        'last_location_id',
        'last_seen_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    /**
     * Bulto físico (shipment_units) al que corresponde este seguimiento, para mostrar en el back office
     * su "N de M" en vez del UUID crudo. Relación de solo lectura: el seguimiento se alimenta por
     * proyección, nunca escribe sobre el bulto.
     *
     * @return BelongsTo<PhysicalUnitRecord, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(PhysicalUnitRecord::class, 'unit_id');
    }

    /**
     * Resuelve la factory del modelo: vive en un namespace de package, no en App\Models.
     */
    protected static function newFactory(): UnitTrackingRecordFactory
    {
        return UnitTrackingRecordFactory::new();
    }
}
