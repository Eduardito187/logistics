<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\Model\Shipment;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;
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
 * @property string|null $order_reference
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

    /**
     * Columnas que SÍ pueden escribirse sobre un envío entregado: metadatos de proyección, no datos del
     * pedido (los completan reconciliaciones y reactores después de la entrega).
     */
    private const array EDITABLE_AFTER_DELIVERY = ['updated_at', 'order_reference'];

    /**
     * Candado del envío entregado a nivel de fila: ninguna escritura (formulario del admin, script o
     * reactor) cambia los datos de un pedido que ya se entregó. Es la red de la regla del agregado
     * ({@see Shipment::isLockedForEdits()}) para los caminos que
     * escriben la fila sin pasar por él. Solo se dejan pasar los metadatos de proyección.
     */
    protected static function booted(): void
    {
        self::updating(static function (self $record): void {
            if (strtoupper((string) $record->getOriginal('status')) !== ShipmentStatus::Delivered->value) {
                return;
            }

            $changed = array_diff(array_keys($record->getDirty()), self::EDITABLE_AFTER_DELIVERY);
            if ($changed !== []) {
                throw ShipmentsException::because('el pedido ya fue entregado: no se puede editar ('.implode(', ', $changed).')');
            }
        });
    }

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
     * Orden estable por `id` (UUID v7, creado en orden de dominio): sin ORDER BY la relación devuelve el
     * orden físico de heap de Postgres, que no es determinista bajo escritura/vacío concurrente. Fijarlo
     * mantiene idéntica la secuencia de líneas del agregado y la del lector SQL del listado (que ya ordena
     * por `id`), y evita diferencias de orden espurias al rehidratar.
     *
     * @return HasMany<ShipmentLineRecord, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ShipmentLineRecord::class, 'shipment_id')->orderBy('id');
    }

    /**
     * Mismo criterio que `lines()`: orden estable por `id` para una rehidratación determinista.
     *
     * @return HasMany<PhysicalUnitRecord, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(PhysicalUnitRecord::class, 'shipment_id')->orderBy('id');
    }
}
