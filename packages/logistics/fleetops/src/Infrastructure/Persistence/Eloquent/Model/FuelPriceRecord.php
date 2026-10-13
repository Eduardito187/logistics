<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\FuelPriceFactory;

/**
 * Modelo Eloquent de la tabla `fleetops_fuel_prices`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio.
 *
 * @property string      $id
 * @property string      $fuel_type
 * @property int         $price_cents
 * @property string      $currency
 * @property string      $effective_from fecha de vigencia en formato Y-m-d (sin cast: siempre texto)
 * @property string|null $source
 */
final class FuelPriceRecord extends Model
{
    /** @use HasFactory<FuelPriceFactory> */
    use HasFactory;

    protected $table = 'fleetops_fuel_prices';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * Fábrica de datos de demo/pruebas de este record (ver FuelPriceFactory).
     */
    protected static function newFactory(): FuelPriceFactory
    {
        return FuelPriceFactory::new();
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
