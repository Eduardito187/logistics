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
use Logistics\Shipments\Infrastructure\ReadModel\ShipmentCityResolver;
use Logistics\Shipments\Infrastructure\ReadModel\ShipmentScopeFieldsResolver;

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
 * @property string|null $city
 * @property string|null $origin_node_id
 * @property string|null $origin_department
 * @property string|null $destination_department
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
    private const array EDITABLE_AFTER_DELIVERY = [
        'updated_at', 'order_reference', 'city', 'origin_node_id', 'origin_department', 'destination_department',
    ];

    /** Datos del envío de los que salen sus campos de alcance (si cambian, el alcance se recalcula). */
    private const array SCOPE_INPUTS = ['destination_node_id', 'city', 'lat', 'lng', 'origin_node_id'];

    /**
     * Candado del envío entregado a nivel de fila: ninguna escritura (formulario del admin, script o
     * reactor) cambia los datos de un pedido que ya se entregó. Es la red de la regla del agregado
     * ({@see Shipment::isLockedForEdits()}) para los caminos que
     * escriben la fila sin pasar por él. Solo se dejan pasar los metadatos de proyección.
     */
    protected static function booted(): void
    {
        // Ciudad canónica del envío: se calcula al guardar cuando falta o cuando cambió la dirección o el nodo
        // destino. Tolerante: si no se puede resolver, queda null.
        self::saving(static function (self $record): void {
            $missing = !is_string($record->getAttribute('city')) || $record->getAttribute('city') === '';
            if (!$missing && !$record->isDirty(['address_text', 'destination_node_id'])) {
                return;
            }

            try {
                $city = app(ShipmentCityResolver::class)->resolve(
                    is_string($record->getAttribute('address_text')) ? $record->getAttribute('address_text') : null,
                    is_string($record->getAttribute('destination_node_id')) ? $record->getAttribute('destination_node_id') : null,
                );
            } catch (\Throwable) {
                return;
            }

            if ($city !== null || $missing) {
                $record->setAttribute('city', $city);
            }
        });

        // Campos de alcance (origen y departamentos): se calculan al guardar cuando faltan o cuando cambió el
        // destino. Va DESPUÉS de la ciudad porque el departamento de un envío a domicilio sale de ella.
        self::saving(static function (self $record): void {
            $record->fillScopeFields();
        });

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

    /**
     * Completa `origin_node_id`, `origin_department` y `destination_department`.
     *
     * Un valor ya presente se respeta (el origen informado por la fuente nunca se pisa); solo se recalcula
     * el departamento cuando cambió el dato del que sale. Tolerante: si la red no se puede leer, el envío
     * se guarda igual con los campos sin resolver.
     */
    private function fillScopeFields(): void
    {
        $inputsChanged = $this->exists && $this->isDirty(self::SCOPE_INPUTS);
        $originNodeId = $this->textAttribute('origin_node_id');
        $complete = $originNodeId !== null
            && $this->textAttribute('origin_department') !== null
            && $this->textAttribute('destination_department') !== null;
        if ($complete && !$inputsChanged) {
            return;
        }

        try {
            $fields = app(ShipmentScopeFieldsResolver::class)->resolve(
                destinationNodeId: $this->textAttribute('destination_node_id'),
                city: $this->textAttribute('city'),
                lat: is_numeric($this->getAttribute('lat')) ? (float) $this->getAttribute('lat') : null,
                lng: is_numeric($this->getAttribute('lng')) ? (float) $this->getAttribute('lng') : null,
                orderReference: $this->textAttribute('order_reference') ?? $this->textAttribute('external_id'),
                originNodeId: $originNodeId,
            );
        } catch (\Throwable) {
            return;
        }

        if ($originNodeId === null && $fields['origin_node_id'] !== null) {
            $this->setAttribute('origin_node_id', $fields['origin_node_id']);
        }

        // El departamento de origen acompaña al nodo de origen: se recalcula si ese nodo cambió.
        $originChanged = $this->exists && $this->isDirty('origin_node_id');
        foreach (['origin_department' => $originChanged, 'destination_department' => $inputsChanged] as $column => $recalculate) {
            $resolved = $fields[$column];
            if ($resolved !== null && ($recalculate || $this->textAttribute($column) === null)) {
                $this->setAttribute($column, $resolved);
            }
        }
    }

    /** Atributo de texto no vacío, o null. */
    private function textAttribute(string $key): ?string
    {
        $value = $this->getAttribute($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
