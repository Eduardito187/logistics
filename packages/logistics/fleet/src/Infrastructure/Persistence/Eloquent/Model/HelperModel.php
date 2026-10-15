<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent de la tabla `fleet_helpers`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio.
 *
 * @property string                                                                                         $id
 * @property string                                                                                         $name
 * @property string|null                                                                                    $phone
 * @property list<array{type: string, expires_on: string}>                                                  $documents
 * @property bool                                                                                           $active
 * @property string|null                                                                                    $photo_path
 * @property string|null                                                                                    $birth_date
 * @property string|null                                                                                    $national_id
 * @property string|null                                                                                    $phone_secondary
 * @property string|null                                                                                    $address
 * @property string|null                                                                                    $emergency_contact_name
 * @property string|null                                                                                    $emergency_contact_phone
 * @property list<array{name: string, relationship?: string|null, phone?: string|null, note?: string|null}> $references
 */
final class HelperModel extends Model
{
    protected $table = 'fleet_helpers';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // `birth_date` se deja sin cast de fecha: Postgres la devuelve como texto 'Y-m-d', que es justo
        // lo que el mapper necesita para construir el DateTimeImmutable del dominio.
        return [
            'documents' => 'array',
            'references' => 'array',
            'active' => 'boolean',
        ];
    }
}
