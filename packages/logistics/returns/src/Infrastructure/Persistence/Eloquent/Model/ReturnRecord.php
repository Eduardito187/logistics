<?php

declare(strict_types=1);

namespace Logistics\Returns\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Logistics\Returns\Database\Factories\ReturnRecordFactory;

/**
 * Modelo Eloquent de la tabla `returns`. Detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio.
 *
 * @property string      $id
 * @property string      $original_reference
 * @property string|null $owner_party_id
 * @property string      $warehouse_id
 * @property string      $fulfillment_mode
 * @property string      $kind
 * @property string|null $disposition
 * @property string      $status
 */
final class ReturnRecord extends Model
{
    /** @use HasFactory<ReturnRecordFactory> */
    use HasFactory;

    protected $table = 'returns';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected static function newFactory(): ReturnRecordFactory
    {
        return ReturnRecordFactory::new();
    }

    /**
     * Líneas de la devolución (sku + cantidad + motivo).
     *
     * @return HasMany<ReturnLineRecord, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ReturnLineRecord::class, 'return_id');
    }
}
