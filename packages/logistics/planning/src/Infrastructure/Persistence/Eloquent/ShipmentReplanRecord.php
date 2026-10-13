<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de la tabla `planning_shipment_replans` (backlog de replanificación). PK uuid string no
 * incremental. Detalle de Infrastructure: no cruza hacia Application ni Domain.
 *
 * @property string      $id
 * @property string      $shipment_id
 * @property string      $plan_date
 * @property string      $reason
 * @property string|null $source_route_id
 */
final class ShipmentReplanRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'planning_shipment_replans';

    protected $guarded = [];
}
