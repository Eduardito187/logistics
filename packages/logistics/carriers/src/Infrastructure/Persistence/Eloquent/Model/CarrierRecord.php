<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

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
    protected $table = 'carriers';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

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
