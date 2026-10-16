<?php

declare(strict_types=1);

namespace Logistics\Inbound\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Logistics\Inbound\Database\Factories\AsnRecordFactory;

/**
 * Modelo Eloquent de la tabla `inbound_asns`. Detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio.
 *
 * @property string      $id
 * @property string      $seller_id
 * @property string      $warehouse_id
 * @property string      $status
 * @property string|null $appointment_date
 * @property string|null $appointment_start
 * @property string|null $appointment_end
 */
final class AsnRecord extends Model
{
    /** @use HasFactory<AsnRecordFactory> */
    use HasFactory;

    protected $table = 'inbound_asns';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected static function newFactory(): AsnRecordFactory
    {
        return AsnRecordFactory::new();
    }

    /**
     * Líneas del ASN (sku + cantidad esperada).
     *
     * @return HasMany<AsnLineRecord, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(AsnLineRecord::class, 'asn_id');
    }
}
