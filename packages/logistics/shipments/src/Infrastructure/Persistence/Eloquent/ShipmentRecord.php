<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\Factories\ShipmentRecordFactory;

/**
 * Fila de la tabla `shipments`. PK uuid string no incremental (UUID v7 generado por el dominio).
 *
 * Es detalle de Infrastructure: nunca cruza hacia Application ni Domain. El mapeo a/desde el agregado
 * lo hace ShipmentMapper.
 *
 * @property string      $id
 * @property string|null $owner_party_id
 * @property string      $fulfillment_mode
 * @property string|null $seller_sku_ref
 * @property string      $source
 * @property string      $external_id
 * @property string      $customer_name
 * @property string|null $customer_document_id
 * @property string|null $customer_phone
 * @property string|null $customer_email
 * @property string      $address_text
 * @property string|null $address_reference
 * @property string|null $lat
 * @property string|null $lng
 * @property int         $address_confidence
 * @property string      $address_confidence_level
 * @property string      $status
 * @property string|null $destination_node_id
 * @property string|null $delivery_date
 * @property string|null $delivery_window_start
 * @property string|null $delivery_window_end
 */
final class ShipmentRecord extends Model
{
    /** @use HasFactory<ShipmentRecordFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'shipments';

    protected $guarded = [];

    /**
     * Factory del contexto (vive en Infrastructure junto al modelo, no en la app).
     */
    protected static function newFactory(): ShipmentRecordFactory
    {
        return ShipmentRecordFactory::new();
    }

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
