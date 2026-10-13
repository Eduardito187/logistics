<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\Ability;
use App\Enums\Role;
use App\Models\UserRole;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Da a un usuario roles operativos y deriva de ellos sus habilidades (abilities).
 *
 * @mixin Model
 */
trait HasRoles
{
    /**
     * @return HasMany<UserRole, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /**
     * Roles del usuario como enums, sin duplicados.
     *
     * @return list<Role>
     */
    public function roles(): array
    {
        /** @var Collection<int, UserRole> $assignments */
        $assignments = $this->roleAssignments()->get();

        $roles = [];
        foreach ($assignments as $assignment) {
            $roles[$assignment->role->value] = $assignment->role;
        }

        return array_values($roles);
    }

    public function hasRole(Role $role): bool
    {
        return $this->roleAssignments()->where('role', $role->value)->exists();
    }

    /**
     * ¿El usuario tiene alguno de estos roles?
     */
    public function hasAnyRole(Role ...$roles): bool
    {
        $values = array_map(static fn (Role $r): string => $r->value, $roles);

        return $this->roleAssignments()->whereIn('role', $values)->exists();
    }

    /**
     * Asigna un rol de forma idempotente.
     */
    public function assignRole(Role $role): void
    {
        $this->roleAssignments()->firstOrCreate(['role' => $role->value]);
    }

    public function removeRole(Role $role): void
    {
        $this->roleAssignments()->where('role', $role->value)->delete();
    }

    /**
     * Unión de las habilidades de todos los roles del usuario.
     *
     * Si algún rol concede el comodín '*', se devuelve solo '*' (acceso total).
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        $abilities = [];
        foreach ($this->roles() as $role) {
            foreach ($role->abilities() as $ability) {
                if ($ability === Ability::WILDCARD) {
                    return [Ability::WILDCARD];
                }
                $abilities[$ability] = $ability;
            }
        }

        return array_values($abilities);
    }
}
