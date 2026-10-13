<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fila de la tabla `shipments`. PK uuid string no incremental (UUID v7 generado por el dominio).
 *
 * Es detalle de Infrastructure: nunca cruza hacia Application ni Domain. El mapeo a/desde el agregado
 * lo hace ShipmentMapper.
 *
 * @property string      $id
 * @property string      $source
 * @property string      $external_id
 * @property string      $customer_name
 * @property string      $address_text
 * @property string|null $address_reference
 * @property string|null $lat
 * @property string|null $lng
 * @property int         $address_confidence
 * @property string      $address_confidence_level
 * @property string      $status
 */
final class ShipmentRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'shipments';

    protected $guarded = [];

    /**
     * @return HasMany<ShipmentLineRecord, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ShipmentLineRecord::class, 'shipment_id');
    }

    /**
     * @return HasMany<PhysicalUnitRecord, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(PhysicalUnitRecord::class, 'shipment_id');
    }
}
