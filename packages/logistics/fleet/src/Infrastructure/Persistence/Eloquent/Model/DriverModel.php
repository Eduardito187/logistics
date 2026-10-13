<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent de la tabla `fleet_drivers`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * @property string       $id
 * @property string       $name
 * @property string       $license_number
 * @property string       $license_expiry
 * @property list<string> $enabled_vehicle_ids
 * @property bool         $active
 */
final class DriverModel extends Model
{
    protected $table = 'fleet_drivers';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // `license_expiry` se deja sin cast: Postgres devuelve la fecha como texto 'Y-m-d', que es
        // justo lo que el mapper necesita para construir el DateTimeImmutable del dominio.
        return [
            'enabled_vehicle_ids' => 'array',
            'active' => 'boolean',
        ];
    }
}
