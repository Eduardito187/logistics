<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

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
 */
final class MaterialMappingRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'sap_material_mappings';

    protected $guarded = [];
}
