<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\TariffFactory;

/**
 * Modelo Eloquent de la tabla `carrier_tariffs`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio.
 *
 * @property string $id
 * @property string $carrier_id
 * @property string $corridor_from
 * @property string $corridor_to
 * @property string $corridor_type
 * @property string $vehicle_class
 * @property int    $price_cents
 * @property string $currency
 * @property string $effective_from fecha de vigencia en formato Y-m-d (sin cast: siempre texto)
 */
final class TariffRecord extends Model
{
    /** @use HasFactory<TariffFactory> */
    use HasFactory;

    protected $table = 'carrier_tariffs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * Fábrica de datos de demo/pruebas de este record (ver TariffFactory).
     */
    protected static function newFactory(): TariffFactory
    {
        return TariffFactory::new();
    }

    /**
     * Transportista dueño de la tarifa.
     *
     * Relación de solo lectura para que el admin muestre el nombre del transportista en vez del UUID;
     * las mutaciones siguen pasando por el comando SetTariff.
     *
     * @return BelongsTo<CarrierRecord, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(CarrierRecord::class, 'carrier_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
        ];
    }
}
