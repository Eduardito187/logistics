<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Factory\PermissionFactory;

/**
 * Modelo Eloquent de la tabla `access_permissions`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio.
 *
 * @property string      $id
 * @property string      $key
 * @property string      $group
 * @property string      $action
 * @property string|null $description
 * @property bool        $is_system
 */
final class PermissionRecord extends Model
{
    /** @use HasFactory<PermissionFactory> */
    use HasFactory;

    protected $table = 'access_permissions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * Fábrica de datos de demo/pruebas de este record (ver PermissionFactory).
     */
    protected static function newFactory(): PermissionFactory
    {
        return PermissionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }
}
