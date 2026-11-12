<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de la carga de un viaje (`carrier_trip_shipments`): un envío dentro de un viaje.
 *
 * Solo lectura para el admin (conteo y listado); el repositorio escribe esta tabla con el query builder.
 *
 * @property string $trip_id
 * @property string $shipment_id
 * @property int    $position
 */
final class CarrierTripShipmentRecord extends Model
{
    protected $table = 'carrier_trip_shipments';

    // PK compuesta (trip_id, shipment_id): Eloquent no la soporta; se usa solo para leer.
    protected $primaryKey = 'shipment_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }
}
