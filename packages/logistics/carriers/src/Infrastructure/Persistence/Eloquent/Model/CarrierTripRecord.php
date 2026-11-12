<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\CarrierTripFactory;

/**
 * Modelo Eloquent de la tabla `carrier_trips`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio.
 *
 * @property string               $id
 * @property string               $carrier_id
 * @property string               $corridor_from
 * @property string               $corridor_to
 * @property string|null          $reference
 * @property string               $status
 * @property CarbonImmutable      $scheduled_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null          $started_by
 * @property string|null          $closed_by
 * @property int|null             $shipments_count
 */
final class CarrierTripRecord extends Model
{
    /** @use HasFactory<CarrierTripFactory> */
    use HasFactory;

    protected $table = 'carrier_trips';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected static function newFactory(): CarrierTripFactory
    {
        return CarrierTripFactory::new();
    }

    /**
     * Transportista del viaje (solo lectura, para mostrar su nombre en el admin en vez del UUID).
     *
     * @return BelongsTo<CarrierRecord, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(CarrierRecord::class, 'carrier_id');
    }

    /**
     * Envíos de la carga (solo lectura para el admin: conteo/listado). Las mutaciones van por comandos.
     *
     * @return HasMany<CarrierTripShipmentRecord, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(CarrierTripShipmentRecord::class, 'trip_id')->orderBy('position');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
