<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asignación de un rol a un usuario (tabla pivote `user_roles`).
 *
 * Un usuario puede tener varios roles; la unión de las habilidades de todos sus roles
 * es lo que se graba en el token al iniciar sesión.
 *
 * @property int  $id
 * @property int  $user_id
 * @property Role $role
 */
class UserRole extends Model
{
    protected $table = 'user_roles';

    /** @var list<string> */
    protected $fillable = ['user_id', 'role'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
