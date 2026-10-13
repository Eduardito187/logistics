<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent de la tabla `fleet_drivers`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * @property string                                                                                         $id
 * @property string                                                                                         $name
 * @property string                                                                                         $license_number
 * @property string                                                                                         $license_expiry
 * @property list<string>                                                                                   $enabled_vehicle_ids
 * @property bool                                                                                           $active
 * @property string|null                                                                                    $photo_path
 * @property string|null                                                                                    $birth_date
 * @property string|null                                                                                    $national_id
 * @property string|null                                                                                    $phone_primary
 * @property string|null                                                                                    $phone_secondary
 * @property string|null                                                                                    $address
 * @property string|null                                                                                    $license_category
 * @property string|null                                                                                    $emergency_contact_name
 * @property string|null                                                                                    $emergency_contact_phone
 * @property list<array{name: string, relationship?: string|null, phone?: string|null, note?: string|null}> $references
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
        // `license_expiry` y `birth_date` se dejan sin cast de fecha: Postgres devuelve la fecha como
        // texto 'Y-m-d', que es justo lo que el mapper necesita para construir el DateTimeImmutable.
        return [
            'enabled_vehicle_ids' => 'array',
            'references' => 'array',
            'active' => 'boolean',
        ];
    }
}
