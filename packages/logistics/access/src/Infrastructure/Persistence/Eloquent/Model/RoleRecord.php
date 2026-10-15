<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Factory\RoleFactory;

/**
 * Modelo Eloquent de la tabla `access_roles`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio.
 *
 * @property string       $id
 * @property string       $key
 * @property string       $name
 * @property string|null  $description
 * @property bool         $is_system
 * @property list<string> $permission_keys
 */
final class RoleRecord extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    protected $table = 'access_roles';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * Fábrica de datos de demo/pruebas de este record (ver RoleFactory).
     */
    protected static function newFactory(): RoleFactory
    {
        return RoleFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'permission_keys' => 'array',
        ];
    }
}
