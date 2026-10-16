<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\Factories\StockTransferRecordFactory;

/**
 * Fila de la tabla `sap_stock_transfers`. PK uuid string no incremental (UUID v7 del dominio).
 *
 * El índice único sobre `event_id` respalda la idempotencia: una recepción (event_id) encola un solo
 * traspaso. Detalle de Infrastructure; el mapeo a/desde el agregado lo hace StockTransferMapper.
 *
 * @property string      $id
 * @property string      $event_id
 * @property string      $shipment_ref
 * @property string      $material
 * @property string      $center
 * @property string      $warehouse
 * @property int         $quantity
 * @property string      $status
 * @property string|null $last_error
 * @property string      $transfer_kind
 * @property string|null $owner_party_id
 *
 * @use HasFactory<StockTransferRecordFactory>
 */
final class StockTransferRecord extends Model
{
    /** @use HasFactory<StockTransferRecordFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'sap_stock_transfers';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    /**
     * Mapeo de material del que proviene la coordenada SAP de este traspaso. Sirve al panel de
     * reconciliación para mostrar el SKU legible de la plataforma junto al código SAP crudo.
     *
     * La coordenada real es material + centro + almacén; esta relación resuelve por `sap_material`
     * (clave del código SAP) como lookup de mejor esfuerzo para la etiqueta legible. No es una clave
     * de negocio: el traspaso ya guarda su coordenada congelada en sus propias columnas.
     *
     * @return BelongsTo<MaterialMappingRecord, $this>
     */
    public function mapping(): BelongsTo
    {
        return $this->belongsTo(MaterialMappingRecord::class, 'material', 'sap_material');
    }

    /**
     * Resuelve la factory del package (no vive en App\, sino en el namespace del contexto).
     */
    protected static function newFactory(): StockTransferRecordFactory
    {
        return StockTransferRecordFactory::new();
    }
}
