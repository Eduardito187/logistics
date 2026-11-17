<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de la tabla `shipment_legs`. PK uuid string no incremental (UUID v7 generado por el dominio).
 *
 * Es detalle de Infrastructure: nunca cruza hacia Application ni Domain. El mapeo a/desde el agregado
 * {@see \Logistics\Shipments\Domain\Model\ShipmentItinerary} lo hace {@see ShipmentLegMapper}.
 *
 * NOTA: `departed_at`/`arrived_at` NO se castean a `datetime` a propósito. El cast de Eloquent escribe el
 * timestamptz sin offset y desfasa la hora (gotcha conocido del proyecto); el mapper lee/escribe el valor
 * como texto ISO-8601, que PostgreSQL interpreta con zona.
 *
 * @property string      $id
 * @property string      $shipment_id
 * @property int         $seq
 * @property string|null $from_node_id
 * @property string|null $to_node_id
 * @property string|null $from_department
 * @property string|null $to_department
 * @property string      $haulage_mode
 * @property string|null $carrier_id
 * @property string      $status
 * @property string|null $departed_at
 * @property string|null $arrived_at
 */
final class ShipmentLegRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'shipment_legs';

    protected $guarded = [];
}
