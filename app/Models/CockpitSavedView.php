<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Vista guardada por un usuario para un listado del admin: pestaña activa + estado de filtros de un
 * recurso, con un nombre, para reutilizarla con un clic (capa CRM). Preferencia personal, no dato de
 * negocio; por eso vive como modelo Eloquent simple en la capa de app (no en un bounded context).
 *
 * @property int                                                      $id
 * @property int|null                                                 $user_id
 * @property string                                                   $resource
 * @property string                                                   $name
 * @property array{tab?: string|null, filters?: array<string, mixed>} $payload
 */
class CockpitSavedView extends Model
{
    protected $table = 'cockpit_saved_views';

    /** @var list<string> */
    protected $fillable = ['user_id', 'resource', 'name', 'payload'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
