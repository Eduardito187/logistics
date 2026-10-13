<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;

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
 * @property-read NodeRecord|null                          $homeBaseNode
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

    /**
     * Nodo base del vehículo (la sede/almacén desde donde opera).
     *
     * Permite mostrar el nombre legible del nodo en la UI vía `homeBaseNode.name` en vez del UUID,
     * y eager-load para evitar N+1.
     *
     * @return BelongsTo<NodeRecord, $this>
     */
    public function homeBaseNode(): BelongsTo
    {
        return $this->belongsTo(NodeRecord::class, 'home_base_node_id');
    }
}
