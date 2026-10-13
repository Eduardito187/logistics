<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

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
    protected $table = 'carrier_tariffs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

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
