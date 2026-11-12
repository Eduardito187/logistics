<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Fila de `planning_shipment_requirements` (una por envío, PK = shipment_id). Proyección de LECTURA para el
 * admin: toda escritura pasa por los comandos SetShipmentRequirement / RemoveShipmentRequirement.
 *
 * @property string      $shipment_id
 * @property string|null $load_type
 * @property string|null $zone
 * @property string|null $height_m
 * @property string|null $weight_kg
 * @property-read ShipmentRecord|null $shipment
 */
final class ShipmentRequirementRecord extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'shipment_id';

    protected $keyType = 'string';

    protected $table = 'planning_shipment_requirements';

    protected $guarded = [];

    /**
     * Envío al que aplican los requisitos (solo lectura hacia Envíos, para mostrar la referencia del pedido
     * y no el UUID).
     *
     * @return BelongsTo<ShipmentRecord, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(ShipmentRecord::class, 'shipment_id');
    }
}
