<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Seeder;

use App\Enums\Ability;
use App\Enums\Role as LegacyRole;
use Illuminate\Database\Seeder;
use Logistics\Access\Domain\ValueObject\PermissionId;
use Logistics\Access\Domain\ValueObject\RoleId;
use Logistics\Access\Domain\ValueObject\UserId;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\PermissionRecord;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\RoleRecord;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\UserRecord;

/**
 * Seeder propio del contexto Access: catálogo base de RBAC y un usuario administrador de demo.
 *
 * Siembra directamente los records Eloquent (patrón de CarriersDemoSeeder), no por el CommandBus: es un
 * seeder de infraestructura que arma la proyección de lectura y no depende del outbox. Es idempotente
 * (firstOrNew + save, con id estable): correrlo varias veces no duplica filas ni cambia las claves.
 *
 * Deriva el catálogo del auth estático heredado (`App\Enums\Ability` y `App\Enums\Role`) para que el RBAC
 * dinámico arranque alineado con los roles y habilidades que la plataforma ya conoce. El integrador lo
 * invoca desde el DatabaseSeeder raíz; también corre con `php artisan db:seed --class=...`.
 */
final class AccessSeeder extends Seeder
{
    /**
     * Permisos de gestión del propio módulo Access (no existen como Ability heredada).
     *
     * @var array<string, string> clave de permiso => descripción en inglés
     */
    private const MODULE_PERMISSIONS = [
        'access.permissions.manage' => 'Manage the permission catalog',
        'access.roles.manage' => 'Manage roles and their permissions',
        'access.users.manage' => 'Manage users and their roles',
        'access.tokens.manage' => 'Manage integration tokens',
    ];

    public function run(): void
    {
        $this->seedPermissions();
        $this->seedRoles();
        $this->seedAdminUser();
    }

    /**
     * Siembra el catálogo de permisos de sistema: una fila por cada Ability heredada ("grupo:accion" →
     * "grupo.accion") más los permisos de gestión del módulo Access.
     */
    private function seedPermissions(): void
    {
        foreach (Ability::all() as $ability) {
            // "network:read" → "network.read" (PermissionKey usa punto como separador).
            $key = str_replace(':', '.', $ability);
            $segments = explode('.', $key);
            $group = $segments[0];
            $action = $segments[count($segments) - 1];

            $this->upsertPermission($key, $group, $action, ucfirst($action).' '.$group);
        }

        foreach (self::MODULE_PERMISSIONS as $key => $description) {
            $segments = explode('.', $key);
            $group = $segments[0];
            $action = $segments[count($segments) - 1];

            $this->upsertPermission($key, $group, $action, $description);
        }
    }

    /**
     * Inserta o actualiza un permiso de sistema conservando su id (idempotente por clave).
     */
    private function upsertPermission(string $key, string $group, string $action, string $description): void
    {
        $record = PermissionRecord::query()->firstOrNew(['key' => $key]);
        if (!$record->exists) {
            $record->id = PermissionId::generate()->toString();
        }
        $record->group = $group;
        $record->action = $action;
        $record->description = $description;
        $record->is_system = true;
        $record->save();
    }

    /**
     * Siembra los 6 roles de sistema derivados de `App\Enums\Role`. Las habilidades se convierten a claves
     * de permiso con punto; el rol admin lleva el comodín "*".
     */
    private function seedRoles(): void
    {
        foreach (LegacyRole::cases() as $role) {
            // "network:read" → "network.read"; el comodín "*" de admin queda igual.
            $permissionKeys = array_values(array_map(
                static fn (string $ability): string => str_replace(':', '.', $ability),
                $role->abilities(),
            ));

            $record = RoleRecord::query()->firstOrNew(['key' => $role->value]);
            if (!$record->exists) {
                $record->id = RoleId::generate()->toString();
            }
            $record->name = $role->label();
            $record->description = $role->label().' role';
            $record->is_system = true;
            $record->permission_keys = $permissionKeys;
            $record->save();
        }
    }

    /**
     * Siembra un usuario administrador de demo (idempotente por correo). La contraseña de demo es
     * 'password123'; se guarda hasheada con bcrypt (helper permitido en infraestructura, no en el dominio).
     */
    private function seedAdminUser(): void
    {
        $record = UserRecord::query()->firstOrNew(['email' => 'admin@dismac.test']);
        if (!$record->exists) {
            $record->id = UserId::generate()->toString();
            $record->password_hash = bcrypt('password123');
        }
        $record->name = 'Administrator';
        $record->status = 'active';
        $record->role_keys = ['admin'];
        $record->save();
    }
}
