<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Service;

use Logistics\Access\Domain\Contract\RoleRepository;
use Logistics\Access\Domain\Model\IntegrationToken;
use Logistics\Access\Domain\Model\User;
use Logistics\Access\Domain\ValueObject\PermissionKey;

/**
 * Resuelve el conjunto efectivo de permisos de un principal.
 *
 * Es el corazón del RBAC: un usuario no declara permisos sueltos, sino roles; sus permisos efectivos
 * son la UNIÓN de los permisos de todos sus roles. Un token de integración, en cambio, lleva sus
 * permisos directos (ver {@see IntegrationToken}). El comodín "*" en
 * cualquier rol o token concede todo y corta la evaluación.
 *
 * Vive en Application: orquesta repositorios de dominio, no conoce HTTP ni Eloquent.
 */
final readonly class PermissionResolver
{
    public function __construct(
        private RoleRepository $roles,
    ) {}

    /**
     * Permisos efectivos de un usuario: unión de los permisos de sus roles.
     *
     * @return list<string>
     */
    public function forUser(User $user): array
    {
        return $this->forRoleKeys($user->roleKeys());
    }

    /**
     * Permisos efectivos para un conjunto de claves de rol. Los roles inexistentes se ignoran (un rol
     * borrado no debe tumbar la resolución; simplemente no aporta permisos).
     *
     * @param  list<string> $roleKeys
     * @return list<string>
     */
    public function forRoleKeys(array $roleKeys): array
    {
        $granted = [];

        foreach ($roleKeys as $roleKey) {
            $role = $this->roles->findByKey($roleKey);
            if ($role === null) {
                continue;
            }

            foreach ($role->permissionKeys() as $permission) {
                if ($permission === PermissionKey::WILDCARD) {
                    return [PermissionKey::WILDCARD];
                }
                $granted[$permission] = $permission;
            }
        }

        return array_values($granted);
    }

    /**
     * ¿El conjunto de permisos concedidos satisface el permiso exigido? Respeta el comodín "*".
     *
     * @param list<string> $granted permisos efectivos (de un usuario o de un token)
     */
    public static function grants(array $granted, string $required): bool
    {
        if (in_array(PermissionKey::WILDCARD, $granted, true)) {
            return true;
        }

        return in_array($required, $granted, true);
    }
}
