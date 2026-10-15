<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Factory\UserFactory;

/**
 * Modelo Eloquent de la tabla `access_users`. Es detalle de infraestructura: nunca cruza a Domain.
 *
 * La PK es un UUID en texto (no autoincremental): el id lo genera el dominio. El hash de la contraseña
 * se oculta ($hidden) para que nunca viaje en serializaciones.
 *
 * @property string                 $id
 * @property string                 $name
 * @property string                 $email
 * @property string                 $password_hash
 * @property string                 $status
 * @property list<string>           $role_keys
 * @property DateTimeInterface|null $last_login_at
 */
final class UserRecord extends Model
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected $table = 'access_users';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['password_hash'];

    /**
     * Fábrica de datos de demo/pruebas de este record (ver UserFactory).
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role_keys' => 'array',
            'last_login_at' => 'datetime',
        ];
    }
}
