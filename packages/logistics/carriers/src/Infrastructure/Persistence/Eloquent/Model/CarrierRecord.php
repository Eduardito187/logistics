<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\CarrierFactory;

/**
 * Modelo Eloquent de la tabla `carriers`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio.
 *
 * @property string                                       $id
 * @property string                                       $name
 * @property string                                       $tax_id
 * @property string                                       $status
 * @property float|null                                   $rating
 * @property int                                          $rating_count
 * @property list<array{type: string, reference: string}> $documents
 */
final class CarrierRecord extends Model
{
    /** @use HasFactory<CarrierFactory> */
    use HasFactory;

    protected $table = 'carriers';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * Fábrica de datos de demo/pruebas de este record (ver CarrierFactory).
     */
    protected static function newFactory(): CarrierFactory
    {
        return CarrierFactory::new();
    }

    /**
     * Tarifas por corredor definidas para este transportista.
     *
     * Relación de solo lectura para que el admin muestre nombres en vez de UUIDs; las mutaciones
     * siguen pasando por los comandos CQRS.
     *
     * @return HasMany<TariffRecord, $this>
     */
    public function tariffs(): HasMany
    {
        return $this->hasMany(TariffRecord::class, 'carrier_id');
    }

    /**
     * Liquidaciones de este transportista.
     *
     * @return HasMany<SettlementRecord, $this>
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(SettlementRecord::class, 'carrier_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'float',
            'rating_count' => 'integer',
            'documents' => 'array',
        ];
    }
}
