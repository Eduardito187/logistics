<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\Factories\MaterialMappingRecordFactory;

/**
 * Fila de la tabla `sap_material_mappings`. PK uuid string no incremental (UUID v7 del dominio).
 *
 * Detalle de Infrastructure: nunca cruza a Application ni Domain. El mapeo a/desde la entidad lo hace
 * MaterialMappingMapper.
 *
 * @property string $id
 * @property string $sku
 * @property string $sap_material
 * @property string $center
 * @property string $warehouse
 *
 * @use HasFactory<MaterialMappingRecordFactory>
 */
final class MaterialMappingRecord extends Model
{
    /** @use HasFactory<MaterialMappingRecordFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'sap_material_mappings';

    protected $guarded = [];

    /**
     * Traspasos de stock cuya coordenada SAP comparte el código de material de este mapeo. Lookup de
     * mejor esfuerzo por `sap_material` para la reconciliación; no es una clave de negocio.
     *
     * @return HasMany<StockTransferRecord, $this>
     */
    public function stockTransfers(): HasMany
    {
        return $this->hasMany(StockTransferRecord::class, 'material', 'sap_material');
    }

    /**
     * Resuelve la factory del package (no vive en App\, sino en el namespace del contexto).
     */
    protected static function newFactory(): MaterialMappingRecordFactory
    {
        return MaterialMappingRecordFactory::new();
    }
}
