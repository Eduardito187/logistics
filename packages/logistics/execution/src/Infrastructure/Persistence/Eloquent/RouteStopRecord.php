<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Fila de la tabla `execution_driver_route_stops`. PK uuid string no incremental.
 *
 * Lleva la foto legible del destino (referencia del pedido, cliente, dirección, ETA, coordenadas) y el
 * estado de la parada. `settled_at` marca cuándo se cerró (entregada o fallida).
 *
 * @property string      $id
 * @property string      $route_id
 * @property string      $shipment_id
 * @property int         $sequence
 * @property string|null $order_reference
 * @property string|null $customer_name
 * @property string|null $address
 * @property string|null $eta
 * @property string|null $lat
 * @property string|null $lng
 * @property string      $status
 * @property string|null $settled_at
 */
final class RouteStopRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'execution_driver_route_stops';

    protected $guarded = [];

    /**
     * Ruta a la que pertenece la parada.
     *
     * @return BelongsTo<VehicleRouteRecord, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(VehicleRouteRecord::class, 'route_id');
    }

    /**
     * Envío que se entrega en esta parada. Relación de solo lectura hacia Shipments para mostrar la
     * referencia y el cliente en lugar del UUID.
     *
     * @return BelongsTo<ShipmentRecord, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(ShipmentRecord::class, 'shipment_id');
    }
}
