<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Modelo Eloquent de la tabla `fleet_checklist_templates`. Detalle de infraestructura: no cruza a Domain.
 *
 * @property string                                                                                                $id
 * @property string                                                                                                $name
 * @property string|null                                                                                           $vehicle_type
 * @property bool                                                                                                  $active
 * @property list<array{key: string, label: string, category: string, criticality: string, requires_photo?: bool}> $items
 * @property Carbon|null                                                                                           $updated_at
 */
final class ChecklistTemplateModel extends Model
{
    protected $table = 'fleet_checklist_templates';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'items' => 'array',
            'active' => 'boolean',
        ];
    }
}
