<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent de la tabla `fleet_helpers`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio.
 *
 * @property string                                        $id
 * @property string                                        $name
 * @property string|null                                   $phone
 * @property list<array{type: string, expires_on: string}> $documents
 * @property bool                                          $active
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
        return [
            'documents' => 'array',
            'active' => 'boolean',
        ];
    }
}
