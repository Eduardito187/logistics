<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent de la tabla `fleet_vehicles`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio.
 *
 * @property string                                        $id
 * @property string                                        $plate
 * @property int                                           $capacity_kg
 * @property float                                         $capacity_m3
 * @property string                                        $home_base_node_id
 * @property string                                        $status
 * @property list<array{type: string, expires_on: string}> $documents
 */
final class VehicleModel extends Model
{
    protected $table = 'fleet_vehicles';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity_kg' => 'integer',
            'capacity_m3' => 'float',
            'documents' => 'array',
        ];
    }
}
