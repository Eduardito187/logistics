<?php

declare(strict_types=1);

namespace Logistics\Sellers\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Logistics\Sellers\Database\Factories\SellerFactory;

/**
 * Modelo Eloquent de la tabla `sellers`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio.
 *
 * @property string      $id
 * @property string      $legal_name
 * @property string      $tax_id
 * @property string      $status
 * @property string      $contact_name
 * @property string      $contact_email
 * @property string|null $contact_phone
 */
final class SellerRecord extends Model
{
    /** @use HasFactory<SellerFactory> */
    use HasFactory;

    protected $table = 'sellers';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * Fábrica de datos de demo/pruebas de este record (ver SellerFactory).
     */
    protected static function newFactory(): SellerFactory
    {
        return SellerFactory::new();
    }

    /**
     * Términos de liquidación pactados con el seller (0 o 1).
     *
     * Relación de solo lectura para el admin/mapper; las mutaciones pasan por los comandos CQRS.
     *
     * @return HasOne<SellerSettlementTermsRecord, $this>
     */
    public function settlementTerms(): HasOne
    {
        return $this->hasOne(SellerSettlementTermsRecord::class, 'seller_id');
    }

    /**
     * SKUs del catálogo del seller.
     *
     * @return HasMany<SellerSkuRecord, $this>
     */
    public function skus(): HasMany
    {
        return $this->hasMany(SellerSkuRecord::class, 'seller_id');
    }
}
